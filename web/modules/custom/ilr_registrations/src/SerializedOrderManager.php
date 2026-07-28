<?php

namespace Drupal\ilr_registrations;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class SerializedOrderService.
 */
class SerializedOrderManager implements SerializedOrderManagerInterface {

  /**
   * Symfony\Component\HttpFoundation\Request definition.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * Constructs a new SerializedOrderService object.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigFactoryInterface $configFactory,
    RequestStack $request_stack
  ) {
    $this->request = $request_stack->getCurrentRequest();
  }

  /**
   * {@inheritdoc}
   */
  public function getObjectForOrder(OrderInterface $order) {
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $promotion_storage = $this->entityTypeManager->getStorage('commerce_promotion');
    $sf_mapping_storage = $this->entityTypeManager->getStorage('salesforce_mapped_object');
    $registration_storage = $this->entityTypeManager->getStorage('registration');

    $items = $order->getItems();
    $customer = $order->getCustomer();
    $billing_profile = $order->getBillingProfile();
    $billing_address = $billing_profile->address->first()->getValue();

    if ($customer_contact_mapping = $sf_mapping_storage->loadByEntity($customer)) {
      /** @var Drupal\salesforce_mapping\Entity\MappedObject $customer_contact_mapping */
      $customer_contact_mapping = reset($customer_contact_mapping);
      $customer_contact_sfid = $customer_contact_mapping->sfid();
    }
    else {
      $customer_contact_sfid = NULL;
    }

    $response = [
      "point_of_sale" => $this->configFactory->get('system.site')->get('name') . ' : ' . $this->request->getHost(),
      "response_webhook_url" => $this->request->getSchemeAndHttpHost() . '/hooks/v1/salesforce-commerce',
      "order_id" => $order->id(),
      "analytics_session_id" => $order->getData('analytics_session_id'),
      "payments" => [],
      "customer" => [
        // `customer_profile_id` was originally planned to be the profile entity
        // id (e.g. $billing_profile->id()), but commerce uses anonymous user
        // profiles for orders to preserve history, and thus we can't get the
        // Drupal user from the profile. This should probably be renamed
        // `customer_user_id`.
        'customer_profile_id' => $customer->id(),
        // Temporarily stop sending the salesforce id per request from DE.
        "contact_sfid" => NULL,
        "email" => $order->getEmail(),
        "first_name" => $billing_address['given_name'],
        "middle_name" => $billing_address['additional_name'],
        "last_name" => $billing_address['family_name'],
        "company" => $billing_address['organization'],
        "address_line1" => $billing_address['address_line1'],
        "address_line2" => $billing_address['address_line2'],
        "city" => $billing_address['locality'],
        "state" => $billing_address['administrative_area'],
        "zip" => $billing_address['postal_code'],
        "country_code" => $billing_address['country_code'],
        "job_title" => $billing_profile->field_job_title->value,
        "industry" => $billing_profile->field_industry->value,
        "phone" => $billing_profile->field_phone->value,
        "additional_fields" => [],
      ],
      "payment_owner" => $order->hasField('field_payment_owner') ? $order->field_payment_owner->value : NULL,
      "order_total" => (float) $order->getTotalPaid()->getNumber(),
      // Set below.
      "order_items" => [],
    ];

    // Add any stored UTM codes if they exist in the order data. See
    // OrderCreateSubscriber::onOrderCreate().
    $utm_codes = $order->getData('utm_codes') ?? [];
    $utm_fields = [];
    $utm_field_map = [
      'UTM_Campaign__c' => 'utm_campaign',
      'UTM_Source__c' => 'utm_source',
      'UTM_Keyword__c' => 'utm_term', // Contacts
      'UTM_Term__c' => 'utm_term', // Participants
      'UTM_Medium__c' => 'utm_medium',
      'UTM_Content__c' => 'utm_content',
    ];

    foreach ($utm_field_map as $sf_utm_field_name => $utm_param_name) {
      $utm_fields[] = [
        'name' => $sf_utm_field_name,
        'value' => $utm_codes[$utm_param_name] ?? null,
      ];
    }

    $response['customer']['additional_fields'] += $utm_fields;

    // Process payments.
    $commerce_payments = $payment_storage->loadByProperties([
      'order_id' => $order->id(),
      'state' => 'completed',
    ]);

    if (!empty($commerce_payments)) {
      $payments = [];

      foreach ($commerce_payments as $commerce_payment) {
        $payment_gateway = $commerce_payment->getPaymentGateway();

        if ($payment_gateway->getPluginId() === 'freedompay_hpp') {
          // Note that this function makes a remote call to the FreedomPay API.
          // @todo Deal with possible remote transaction retrieval failure.
          $transaction = $payment_gateway->getPlugin()->getTransaction($commerce_payment->getRemoteId());
        }
        elseif ($payment_gateway->getPluginId() === 'cardpointe_hpp') {
          $data = json_decode($commerce_payment->data->value, TRUE);
          $transaction = [];
          $transaction['CardIssuer'] = $data['cardType'];
          $transaction['MaskedCardNumber'] = $data['number'];
          $transaction['NameOnCard'] = $data['billFName'] . ' ' . $data['billLName'];
          // @todo Fix this legacy structure once Salesforce webhook is refactored.
          $transaction['AuthResponse']['FreewayResponse']['AuthorizationCode'] = $data['authCode'];
          $transaction['cardpoint_hpp'] = $data;
        }
        elseif ($payment_gateway->getPluginId() === 'cardpointe_api') {
          $payment_method = $commerce_payment->getPaymentMethod();

          /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $payment_method_billing_address */
          $payment_method_billing_address = $payment_method->getBillingProfile()->get('address')->first();

          $transaction = [];
          $transaction['CardIssuer'] = $payment_method->card_type->value;
          $transaction['MaskedCardNumber'] = $payment_method->card_number->value;
          $transaction['NameOnCard'] = $payment_method_billing_address->getGivenName() . ' ' . $payment_method_billing_address->getFamilyName();
          // @todo Fix this if Salesforce webhook is ever refactored.
          $transaction['AuthResponse']['FreewayResponse']['AuthorizationCode'] = $commerce_payment->authcode ? $commerce_payment->authcode->value : '---';
        }
        else {
          $transaction = NULL;
        }

        $payments[] = [
          "payment_type" => $payment_gateway->getPluginId(),
          "payment_id" => $commerce_payment->id(),
          "amount" => (float) $commerce_payment->getAmount()->getNumber(),
          "transaction_id" => $commerce_payment->getRemoteId(),
          "transaction_data" => $transaction,
        ];
      }

      $response['payments'] = $payments;
    }

    // Process order items.
    foreach ($items as $item) {
      $discounts = [];
      $item_adjustments = $item->getAdjustments();

      // Process discounts for this item.
      foreach ($item_adjustments as $item_adjustment) {
        if ($item_adjustment->getType() === 'promotion') {
          $promotions = $promotion_storage->loadByProperties([
            'promotion_id' => $item_adjustment->getSourceId(),
          ]);

          if (!empty($promotions)) {
            foreach ($promotions as $promotion) {
              $sf_promo_mapped_objects = $sf_mapping_storage->loadByEntity($promotion);
              $sf_promo_mapped_object = reset($sf_promo_mapped_objects);

              $discount = [
                "sfid" => $sf_promo_mapped_object->sfid(),
                "code" => $promotion->label(),
                "type" => $item_adjustment->getPercentage() ? 'percentage' : 'fixed_amount',
                "amount" => (float) $item_adjustment->getAmount()->getNumber(),
                "percentage" => (float) $item_adjustment->getPercentage(),
              ];

              $discounts[] = $discount;
            }
          }
        }
        elseif ($item_adjustment->getType() === 'ilr_outreach_discount') {
          [$sfid, $code] = explode(',', $item_adjustment->getSourceId());
          $discount = [
            "sfid" => $sfid,
            "code" => $code,
            "type" => $item_adjustment->getPercentage() ? 'percentage' : 'fixed_amount',
            "amount" => (float) $item_adjustment->getAmount()->getNumber(),
            "percentage" => (float) $item_adjustment->getPercentage(),
          ];

          $discounts[] = $discount;
        }
        elseif ($item_adjustment->getType() === 'ilr_outreach_auto_discount') {
          [$sfid, $code] = explode(',', $item_adjustment->getSourceId());
          $discount = [
            "sfid" => $sfid,
            "code" => $code,
            "type" => $item_adjustment->getPercentage() ? 'percentage' : 'fixed_amount',
            "amount" => (float) $item_adjustment->getAmount()->getNumber(),
            "percentage" => (float) $item_adjustment->getPercentage(),
          ];

          $discounts[] = $discount;
        }
      }

      // Process registrations and participants for this item.
      $participants = [];
      $registrations = $registration_storage->loadByProperties([
        'commerce_order_item_id' => $item->id(),
      ]);

      if (!empty($registrations)) {
        /** @var \Drupal\erf\Entity\RegistrationInterface $registration */
        $registration = reset($registrations);

        foreach ($registration->participants->referencedEntities() as $delta => $participant) {
          // @todo handle participants even if there is not an address field
          $address_value = $participant->field_address->getValue();
          $address = reset($address_value);
          $participants[$delta] = [
            'participant_id' => $participant->id(),
            // Temporarily stop sending the salesforce id per request from DE.
            "contact_sfid" => NULL,
            'email' => $participant->mail->value,
            "first_name" => $address['given_name'],
            "middle_name" => $address['additional_name'],
            "last_name" => $address['family_name'],
            "company" => $address['organization'],
            "address_line1" => $address['address_line1'],
            "address_line2" => $address['address_line2'],
            "city" => $address['locality'],
            "state" => $address['administrative_area'],
            "zip" => $address['postal_code'],
            "country_code" => $address['country_code'],
            "job_title" => $participant->hasField('field_job_title') ? $participant->field_job_title->value : NULL,
            "industry" => $participant->hasField('field_industry') ? $participant->field_industry->value : NULL,
            "phone" => $participant->hasField('field_phone') ? $participant->field_phone->value : NULL,
            "dietary_restrictions" => $participant->hasField('field_dietary_restrictions') ? $participant->field_dietary_restrictions->value : NULL,
            "accessible_accommodation" => $participant->hasField('field_accessible_accommodation') ? substr($participant->field_accessible_accommodation->value ?? '', 0, 250) : NULL,
            "is_cornell_employee" => $participant->hasField('field_is_cornell_employee') ? ($participant->field_is_cornell_employee->value ? 'true' : 'false') : NULL,
            "apply_to_certificate" => "",
            // LAI-only fields.
            "attendee_role_description" => NULL,
            "how_did_you_hear_about_us" =>  NULL,
            // @todo Add additional participant fields as necessary.
            // 'additional_fields' => $utm_fields,
            'additional_fields' => [],
          ];

          if ($registration->bundle() === 'lai_conference') {
            $attendee_type = $participant->hasField('field_attendee_type') ? $participant->field_attendee_type->value : NULL;

            if ($attendee_type === 'other') {
              $attendee_type = $participant->hasField('field_attendee_type_other') ? $participant->field_attendee_type_other->value : NULL;
            }

            $participants[$delta]['attendee_role_description'] = $attendee_type;
            $participants[$delta]['how_did_you_hear_about_us'] = $registration->hasField('field_source') ? $registration->field_source->value : NULL;
          }
        }
      }

      $response['order_items'][] = [
        'name' => $item->getTitle(),
        "discounts" => $discounts,
        "price" => (float) $item->getUnitPrice()->getNumber(),
        "discounted_price" => (float) $item->getAdjustedUnitPrice()->getNumber(),
        "quantity" => (int) $item->getQuantity(),
        "total" => (float) $item->getTotalPrice()->getNumber(),
        "discounted_total" => (float) $item->getAdjustedTotalPrice()->getNumber(),
        "product" => [
          "product_type" => "registration",
          // Not in the spec, but maybe still useful.
          "x_product_type_subtype" => $item->bundle(),
          // @todo Add additional product fields as necessary.
          "additional_fields" => [],
          "course_id" => $item->getData('sf_course_id'),
          "class_id" => $item->getData('sf_class_id'),
          "participants" => $participants,
        ],
      ];
    }

    return $response;
  }

}
