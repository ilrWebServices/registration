<?php

namespace Drupal\ilr_outreach_discounts;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderProcessorInterface;
use Drupal\commerce_order\Adjustment;
use Drupal\commerce_price\Price;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ilr_outreach_discount_api\DiscountNotFoundException;
use Drupal\ilr_outreach_discount_api\IlrOutreachDiscountManager;

/**
 * Provides an order processor that adds ILR Outreach auto discounts.
 *
 * Note that these are in addition to any discount codes applied during checkout
 * via IlrOutreachDiscountOrderProcessor.
 */
class IlrOutreachAutoDiscountOrderProcessor implements OrderProcessorInterface {

  use StringTranslationTrait;
  use LoggerChannelTrait;
  use MessengerTrait;

  /**
   * Constructs a new IlrOutreachAutoDiscountOrderProcessor object.
   */
  public function __construct(
    protected IlrOutreachDiscountManager $ilrOutreachDiscountManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(OrderInterface $order) {
    foreach ($order->getItems() as $order_item) {
      // Check if the order item is for a Salesforce class/event.
      if (!$sf_class_id = $order_item->getData('sf_class_id')) {
        continue;
      }

      try {
        $discount = $this->ilrOutreachDiscountManager->getAutoApplyDiscountForClass($sf_class_id);
      }
      catch (DiscountNotFoundException $e) {
        continue;
      }
      catch (\Exception $e) {
        $this->getLogger('ilr_outreach_auto_discount')->error($e->getMessage());
        continue;
      }

      if ($discount->type === 'percentage') {
        $adjustment_amount = $order_item->getUnitPrice()->multiply($discount->value)->multiply($order_item->getQuantity());
      }
      else {
        $adjustment_amount = (new Price($discount->value, 'USD'))->multiply($order_item->getQuantity());
      }

      // Apply the discount.
      $order_item->addAdjustment(new Adjustment([
        'type' => 'ilr_outreach_auto_discount',
        'label' => $discount->description,
        // This source_id is a hacky CSV. This allows us to parse out the sfid
        // and code for serializing.
        'source_id' => $discount->sfid . ',' . $discount->code,
        'amount' => $adjustment_amount,
        'percentage' => ($discount->type === 'percentage') ? (string) $discount->value : NULL,
      ]));
    }
  }

}
