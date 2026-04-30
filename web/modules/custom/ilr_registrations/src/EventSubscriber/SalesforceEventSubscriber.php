<?php

namespace Drupal\ilr_registrations\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\salesforce\Event\SalesforceEvents;
use Drupal\salesforce_mapping\Event\SalesforceQueryEvent;
use Drupal\salesforce_mapping\Event\SalesforcePullEvent;

/**
 * Event subscriber for salesforce pull events.
 */
class SalesforceEventSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [
      SalesforceEvents::PULL_QUERY => 'pullQueryAlter',
      SalesforceEvents::PULL_PRESAVE => 'pullPresave',
    ];
    return $events;
  }

  /**
   * SalesforceQueryEvent pull query alter event callback.
   *
   * @param \Drupal\salesforce_mapping\Event\SalesforceQueryEvent $event
   *   The event.
   */
  public function pullQueryAlter(SalesforceQueryEvent $event) {
    // Add some additional fields to the `discount_promotion` mapping. They'll
    // be used in self::pullPresave().
    if ($event->getMapping()->id() === 'discount_promotion') {
      $query = $event->getQuery();
      $query->fields[] = "Discount_Type__c";
      $query->fields[] = "Discount_Percent__c";
      $query->fields[] = "Discount_Amount__c";
    }
  }

  /**
   * Pull presave event callback.
   *
   * @param \Drupal\salesforce_mapping\Event\SalesforcePullEvent $event
   *   The event.
   */
  public function pullPresave(SalesforcePullEvent $event) {
    // Modify commerce_promotions before they are saved after a SF pull.
    if ($event->getMapping()->id() === 'discount_promotion') {
      $commerce_promotion = $event->getEntity();
      $default_store = \Drupal::service('commerce_store.default_store_resolver')->resolve();

      // Ensure that this promotion has a store assigned. It can be missing if
      // the promotion is imported via salesforce.
      if (count($commerce_promotion->getStoreIds()) === 0 && $default_store) {
        // Set the default store for this storeless promotion.
        $commerce_promotion->setStoreIds([$default_store->id()]);
      }

      // Ensure that this promotion has at least one order type. It can be
      // missing if the promotion is imported via salesforce.
      if (count($commerce_promotion->getOrderTypeIds()) === 0 && $default_store) {
        // Set the order type for this typeless promotion.
        $commerce_promotion->setOrderTypeIds(['registration']);
      }

      $sf = $event->getMappedObject()->getSalesforceRecord();

      // Set the promotion offer type based on sf object settings. Note that the
      // fields `Discount_Type__c`, `Discount_Percent__c`, and
      // `Discount_Amount__c` are not mapped, but are instead added in
      // self::pullQueryAlter().
      if ($sf->field('Discount_Type__c') === 'Individual_Percentage') {
        $offer = [
          'target_plugin_id' => 'order_percentage_off',
          'target_plugin_configuration' => [
            'percentage' => (string) ($sf->field('Discount_Percent__c') / 100),
          ],
        ];
      }
      else {
        $offer = [
          'target_plugin_id' => 'order_fixed_amount_off',
          'target_plugin_configuration' => [
            'amount' => [
              'number' => (string) $sf->field('Discount_Amount__c'),
              'currency_code' => 'USD',
            ],
          ],
        ];
      }

      $commerce_promotion->offer = $offer;

      // Add a 'Discount Eligible' product category condition, too, but only on
      // initial import. Note that this will only work if the
      // `discount_promotion` mapping runs after the `course_product` mapping,
      // since the 'Discount Eligible' term is created during product import.
      // @see ilr_registrations_commerce_product_presave().
      if ($commerce_promotion->isNew()) {
        $entity_type_manager = \Drupal::service('entity_type.manager');

        // Get the 'Discount Eligible' term.
        $discount_eligible_term = $entity_type_manager->getStorage('taxonomy_term')->loadByProperties([
          'name' => 'Discount Eligible',
          'vid' => 'product_tags',
        ]);
        $discount_eligible_term = reset($discount_eligible_term);

        if ($discount_eligible_term) {
          $commerce_promotion->conditions = [
            [
              'target_plugin_id' => 'order_product_category',
              'target_plugin_configuration' => [
                'terms' => [$discount_eligible_term->uuid()],
              ],
            ],
          ];
        }
      }
    }

    // Modify commerce_products before they are saved after a SF pull.
    if ($event->getMapping()->id() === 'course_product') {
      $commerce_product = $event->getEntity();
      $sf = $event->getMappedObject()->getSalesforceRecord();

      // Set the registration type to `simple_class` by default.
      if ($commerce_product->hasField('registration_type')) {
        if (strpos($sf->field('Course_Number__c'), 'LAICONF') === 0) {
          $commerce_product->registration_type = 'lai_conference';
        }
        else {
          $commerce_product->registration_type = 'simple_class';
        }
      }
    }
  }

}
