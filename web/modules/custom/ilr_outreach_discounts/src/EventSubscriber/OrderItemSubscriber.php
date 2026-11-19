<?php

namespace Drupal\ilr_outreach_discounts\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\commerce_cart\Event\CartEvents;
use Drupal\ilr_outreach_discount_api\IlrOutreachDiscountManager;
use Drupal\commerce_cart\Event\CartOrderItemAddEvent;
use Drupal\persistent_visitor_parameters\PersistenceManager;

/**
 * An event subscriber for order items.
 */
class OrderItemSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new OrderItemSubscriber object.
   *
   * @param \Drupal\ilr_outreach_discount_api\IlrOutreachDiscountManager $discountManager
   *   The ILR Outreach discount manager.
   * @param \Drupal\persistent_visitor_parameters\PersistenceManager $pvpManager
   *   The persistent visitor paramater persistence manager.
   */
  public function __construct(
    protected IlrOutreachDiscountManager $discountManager,
    protected PersistenceManager $pvpManager
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return array
   *   The event names to listen for, and the methods that should be executed.
   */
  public static function getSubscribedEvents(): array {
    return [
      CartEvents::CART_ORDER_ITEM_ADD => 'onCartOrderItemInsert',
    ];
  }

  /**
   * Callback for the cart order item insert event.
   *
   * @param \Drupal\commerce_cart\Event\CartOrderItemAddEvent $event
   *   The event.
   */
  public function onCartOrderItemInsert(CartOrderItemAddEvent $event) {
    $item = $event->getOrderItem();
    $order = $event->getCart();

    if ($item->bundle() !== 'class' || !$item->getData('sf_class_id')) {
      return;
    }

    $discount_codes = [];

    if ($pvp_stored_variables = $this->pvpManager->loadPersistedParameters()) {
      foreach ($pvp_stored_variables as $key => $val) {
        $val = preg_replace("/[^a-z0-9'\/\-_]/i", '', $val);

        if ($key === 'ilr_discount_code') {
          $discount_codes[] = $val;
        }
      }
    }

    // Look for any discount codes in an env var.
    if ($env_disount_codes = getenv('ILR_DISCOUNT_CODES')) {
      $discount_codes = array_merge($discount_codes, explode(';', $env_disount_codes));
    }

    if ($discount_codes && $discount = $this->discountManager->getEligibleDiscount($discount_codes[0], $item->getData('sf_class_id'))) {
      $order->setData('ilr_outreach_discounts', [$discount->code => $discount]);
    }
    else {
      $order->setData('ilr_outreach_discounts', []);
    }
  }

}
