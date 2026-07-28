<?php

namespace Drupal\ilr_outreach_discounts;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_price\Price;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Provides an order processor that calculates ILR Outreach discounts.
 */
class IlrOutreachDiscountOrderProcessor implements OrderProcessorInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order) {
    /** @var \Drupal\ilr_outreach_discount_api\IlrOutreachDiscount $discount */
    foreach ($order->getData('ilr_outreach_discounts', []) as $discount) {
      foreach ($order->getItems() as $order_item) {
        // If this is not a universal discount, only apply it to this item if
        // the discount applies to this class.
        if (!$discount->universal && !in_array($order_item->getData('sf_class_id'), $discount->appliesTo)) {
          continue;
        }

        // Also skip items that are excluded from universal discounts.
        if ($discount->universal && in_array($order_item->getData('sf_class_id'), $discount->excludes)) {
          continue;
        }

        // @todo Consider preventing adjustments greater than the item price.
        if ($discount->type === 'percentage') {
          $adjustment_amount = $order_item->getUnitPrice()->multiply($discount->value)->multiply($order_item->getQuantity());
        }
        else {
          $adjustment_amount = (new Price($discount->value, 'USD'))->multiply($order_item->getQuantity());
        }

        $order_item->addAdjustment(new Adjustment([
          'type' => 'ilr_outreach_discount',
          'label' => $discount->code . ' ' . $this->t('discount for') . ' ' . $order_item->label(),
          // This source_id is a hacky CSV. This allows us to parse out the sfid
          // and code for serializing.
          'source_id' => $discount->sfid . ',' . $discount->code . ',' . $order_item->id(),
          'amount' => $adjustment_amount,
          'percentage' => ($discount->type === 'percentage') ? (string) $discount->value : NULL,
        ]));
      }
    }
  }

}
