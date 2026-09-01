<?php

namespace Drupal\commerce_cardconnect_api\Plugin\Commerce\PaymentGateway;

use CardPointeGateway\CardPointeGatewayRestClient;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\AuthenticationException;
use Drupal\commerce_payment\Exception\DeclineException;
use Drupal\commerce_payment\Exception\InvalidResponseException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;

/**
 * Provides the CardPointe Gateway API payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "cardpointe_api",
 *   label = @Translation("CardPointe Gateway API"),
 *   display_label = @Translation("CardPointe Gateway API"),
 *   forms = {
 *     "add-payment-method" = "Drupal\commerce_cardconnect_api\PluginForm\CardPointeApiPaymentMethodAddForm",
 *   },
 *   payment_type = "payment_cardpointe",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "mastercard", "visa", "amex",
 *   }
 * )
 */
class CardPointeApi extends OnsitePaymentGatewayBase implements SupportsRefundsInterface {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'cp_user' => '',
      'cp_pass' => '',
      'cp_site' => 'fts',
      'cp_merchant_id' => '',
      'attempt_refunds' => false,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['cp_user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CardPointe API username'),
      '#description' => $this->t('For production only. The username is configured automatically in test mode as per @link.', [
        '@link' => Link::fromTextAndUrl($this->t('the API Developer Guide'), Url::fromUri('https://developer.cardpointe.com/guides/cardpointe-gateway#uat-api-credentials'))->toString(),
      ]),
      '#default_value' => $this->configuration['cp_user'],
      '#required' => TRUE,
    ];

    $form['cp_pass'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CardPointe API password'),
      '#description' => $this->t('For production only. The password is configured automatically in test mode as per @link.', [
        '@link' => Link::fromTextAndUrl($this->t('the API Developer Guide'), Url::fromUri('https://developer.cardpointe.com/guides/cardpointe-gateway#uat-api-credentials'))->toString(),
      ]),
      '#default_value' => $this->configuration['cp_pass'],
      '#required' => TRUE,
    ];

    $form['cp_site'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CardPointe API site'),
      '#description' => $this->t('See @link and your service configuration. Note: Do not append `-uat` here for testing. The test mode will do that automatically.', [
        '@link' => Link::fromTextAndUrl($this->t('the API Connectivity Guide'), Url::fromUri('https://developer.cardpointe.com/guides/api-connectivity#web-service-uRLs'))->toString(),
      ]),
      '#default_value' => $this->configuration['cp_site'],
      '#required' => TRUE,
    ];

    $form['cp_merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['cp_merchant_id'],
      '#required' => TRUE,
    ];

    $form['attempt_refunds'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Attempt refunds'),
      '#description' => $this->t('When checked, refund operations will actually attempt to return funds to the card used in the transaction. Otherwise, payments will only be <em>marked</em> as refunded. Leave this setting unchecked when refunds are processed via other means.'),
      '#default_value' => $this->configuration['attempt_refunds'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['cp_user'] = $values['cp_user'];
    $this->configuration['cp_pass'] = $values['cp_pass'];
    $this->configuration['cp_site'] = $values['cp_site'];
    $this->configuration['cp_merchant_id'] = $values['cp_merchant_id'];
    $this->configuration['attempt_refunds'] = $values['attempt_refunds'];
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    // Gather necessary info from the payment, payment method, and order.
    $payment_method = $payment->getPaymentMethod();

    /** @var \Drupal\commerce_order\Entity\Order $order */
    $order = $payment->getOrder();

    /** @var \\Drupal\profile\Entity\ProfileInterface $billing_profile */
    $billing_profile = $payment_method->getBillingProfile();

    // Perform verifications, throwing exceptions as needed.
    $this->assertPaymentState($payment, ['new']);
    $this->assertPaymentMethod($payment_method);

    /** @var \Drupal\commerce_price\NumberFormatter $number_formatter */
    $number_formatter = \Drupal::service('commerce_price.number_formatter');

    // Perform the API request(s), throwing exceptions as needed.
    try {
      $is_live = $this->getMode() === 'live';
      $client = new CardPointeGatewayRestClient([
        // @see https://developer.cardpointe.com/guides/cardpointe-gateway#uat-api-credentials
        'cp_user' => $is_live ? $this->configuration['cp_user'] : 'testing',
        'cp_pass' => $is_live ? $this->configuration['cp_pass'] : 'testing123',
        'cp_site' => $this->configuration['cp_site'] . ($is_live ? '' : '-uat'),
      ]);

      // The 'Payment process' pane settings determine the 'capture' value.
      $data = [
        // @see https://developer.cardpointe.com/guides/cardpointe-gateway#uat-merchant-id
        'merchid' => $is_live ? $this->configuration['cp_merchant_id'] : '800000001509',
        'amount' => $number_formatter->format($payment->getAmount()->getNumber(), [
          'minimum_fraction_digits' => 2,
          'use_grouping' => FALSE,
        ]),
        'expiry' => $payment_method->card_exp_year->value . $payment_method->card_exp_month->value,
        'account' => $payment_method->remote_id->value,
        'orderid' => $order->id(),
        'capture' => $capture ? 'Y' : 'N',
        'email' => $order->getEmail(),
        'userfields' => [
          [
            'payment_gateway' => $payment_method->getPaymentGateway()->label(),
          ],
          [
            'order_url' => $order->toUrl('canonical', ['absolute' => TRUE])->toString(),
          ],
        ],
      ];

      foreach ($order->getItems() as $item) {
        $data['userfields'][] = [
          'item_' . $item->id() => $item->label(),
        ];
      }

      if ($billing_profile) {
        /** @var \Drupal\address\Plugin\Field\FieldType\AddressItem $billing_address */
        $billing_address = $billing_profile->get('address')->first();
        $data['name'] = $billing_address->getGivenName() . ' ' . $billing_address->getFamilyName();
        $data['postal'] = $billing_address->getPostalCode();
      }

      /** @var \CardPointeGateway\Psr7\DataAwareResponse $client_response */
      $client_response = $client->post('auth', ['json' => $data]);
    }
    // Could not connect to server or other network issue.
    catch (ConnectException $e) {
      throw new AuthenticationException('CardConnect API ' . $e->getMessage());
    }
    // 4xx error. This is either a 400 Bad Request (e.g. invalid syntax) or
    // 401 Unauthorized (e.g. bad credentials).
    catch (ClientException $e) {
      // print_r($e->getResponse()->getData());
      throw new AuthenticationException('CardConnect API ' . $e->getMessage());
    }
    // 5xx error. This is an 'Internal Server Error'.
    catch (ServerException $e) {
      // print_r($e->getResponse()->getData());
      throw new AuthenticationException('CardConnect API ' . $e->getMessage());
    }
    catch (\Exception $e) {
      throw new AuthenticationException('CardConnect API error: ' . $e->getMessage());
    }

    $response = $client_response->getData();

    // As far as we can tell, `respstat` is normalized between different credit
    // card processors, so A = Approval, B = Temporary processing issue, such as
    // a network error, and C = Rejection. See
    // https://developer.cardpointe.com/gateway-response-codes
    if ($response['respstat'] === 'C') {
      if ($response['resptext'] === 'Invalid token') {
        $this->messenger()->addError($this->t('Please check your card number.'));
      }

      if (!$is_live) {
        $this->messenger()->addError($this->t('Testing mode response text: @resptext', [
          '@resptext' => $response['resptext'],
        ]));
      }

      throw new DeclineException('CardConnect API rejection response: ' . $response['resptext']);
    }
    elseif ($response['respstat'] === 'B') {
      throw new InvalidResponseException('CardConnect API processing issue: ' . $response['resptext']);
    }

    // Create and save information to a Commerce payment entity.
    $next_state = $capture ? 'completed' : 'authorization';

    $payment->setState($next_state);
    $payment->setRemoteId($response['retref']);
    $payment->setAuthorizedTime(\Drupal::time()->getRequestTime());
    $payment->setAvsResponseCode($response['avsresp']);

    // This payment gateway uses a custom payment type with an authcode field.
    // @see Drupal\commerce_cardconnect_api\Plugin\Commerce\PaymentType\PaymentCardpointe
    if (!empty($response['authcode'])) {
      $payment->set('authcode', $response['authcode']);
    }

    if (!$payment_method->card_type->isEmpty()) {
      $avs_response_code_label = $this->buildAvsResponseCodeLabel($response['avsresp'], $payment_method->card_type->value);
      $payment->setAvsResponseCodeLabel($avs_response_code_label);
    }

    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function refundPayment(PaymentInterface $payment, ?Price $amount = NULL) {
    $this->assertPaymentState($payment, ['completed', 'partially_refunded']);
    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $this->assertRefundAmount($payment, $amount);

    if ($this->shouldAttemptRefunds()) {
      try {
        /** @var \Drupal\commerce_price\NumberFormatter $number_formatter */
        $number_formatter = \Drupal::service('commerce_price.number_formatter');

        $is_live = $this->getMode() === 'live';
        $client = new CardPointeGatewayRestClient([
          // @see https://developer.cardpointe.com/guides/cardpointe-gateway#uat-api-credentials
          'cp_user' => $is_live ? $this->configuration['cp_user'] : 'testing',
          'cp_pass' => $is_live ? $this->configuration['cp_pass'] : 'testing123',
          'cp_site' => $this->configuration['cp_site'] . ($is_live ? '' : '-uat'),
        ]);

        // @see https://developer.cardpointe.com/cardconnect-api?lang=json#refund
        $data = [
          'merchid' => $is_live ? $this->configuration['cp_merchant_id'] : '496160873888',
          'retref' => $payment->getRemoteId(),
          'amount' => $number_formatter->format($amount->getNumber(), [
            'minimum_fraction_digits' => 2,
            'use_grouping' => FALSE,
          ]),
        ];

        /** @var \CardPointeGateway\Psr7\DataAwareResponse $client_response */
        $client_response = $client->put('refund', ['json' => $data]);
      }
      // Throw an exception if the refund fails.
      catch (\Exception $e) {
        throw new PaymentGatewayException('Error message about the failure');
      }

      $response = $client_response->getData();

      // As far as we can tell, `respstat` is normalized between different credit
      // card processors, so A = Approval, B = Temporary processing issue, such as
      // a network error, and C = Rejection. See
      // https://developer.cardpointe.com/gateway-response-codes
      if ($response['respstat'] === 'C') {
        throw new PaymentGatewayException('Refund rejected: ' . $response['resptext']);
      }
    }

    // Determine whether payment has been fully or partially refunded.
    $old_refunded_amount = $payment->getRefundedAmount();
    $new_refunded_amount = $old_refunded_amount->add($amount);

    if ($new_refunded_amount->lessThan($payment->getAmount())) {
      $payment->setState('partially_refunded');
    }
    else {
      $payment->setState('refunded');
    }

    $payment->setRefundedAmount($new_refunded_amount);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $payment_method->save();
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the remote record here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Delete the local entity.
    $payment_method->delete();
  }

  /**
   * Get CardPointe API transaction info for a given retref.
   *
   * @return array|false
   *   The response data from a transaction inquiry. See
   *   https://developer.cardpointe.com/cardconnect-api?lang=xml#inquire-response
   */
  public function getTransaction($retref) {
    try {
      // @todo Refactor this to a method to use here and in createPayment().
      $is_live = $this->getMode() === 'live';
      $client = new CardPointeGatewayRestClient([
        'cp_user' => $is_live ? $this->configuration['cp_user'] : 'testing',
        'cp_pass' => $is_live ? $this->configuration['cp_pass'] : 'testing123',
        'cp_site' => $this->configuration['cp_site'] . ($is_live ? '' : '-uat'),
      ]);

      $response = $client->get(strtr('inquire/<retref>/<merchid>', [
        '<retref>' => (int) $retref,
        '<merchid>' => $this->configuration['cp_merchant_id'],
      ]));
    }
    catch (ClientException $e) {
      return FALSE;
    }

    return $response->getData();
  }

  /**
   * Determines if refunds should actually be attempted.
   *
   * @return boolean TRUE if refunds should be attempted via the API.
   */
  public function shouldAttemptRefunds() {
    return (bool) $this->configuration['attempt_refunds'];
  }

}
