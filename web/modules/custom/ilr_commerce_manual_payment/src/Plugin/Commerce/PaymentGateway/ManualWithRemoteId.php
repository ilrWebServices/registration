<?php

namespace Drupal\ilr_commerce_manual_payment\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\Manual;
use Drupal\commerce_price\Price;

/**
 * Provides the Manual payment gateway that includes a remote id.
 *
 * @CommercePaymentGateway(
 *   id = "manual_with_remote_id",
 *   label = "Manual (including Remote ID)",
 *   display_label = "Manual",
 *   modes = {
 *     "n/a" = @Translation("N/A"),
 *   },
 *   forms = {
 *     "add-payment" = "Drupal\ilr_commerce_manual_payment\PluginForm\ManualPaymentAddForm",
 *     "receive-payment" = "Drupal\ilr_commerce_manual_payment\PluginForm\PaymentReceiveForm",
 *   },
 *   payment_type = "payment_manual",
 *   requires_billing_information = FALSE,
 * )
 */
class ManualWithRemoteId extends Manual {

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $received = FALSE, $remote_id = NULL) {
    $this->assertPaymentState($payment, ['new']);

    $payment->state = $received ? 'completed' : 'pending';
    $payment->setRemoteId($remote_id);
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function receivePayment(PaymentInterface $payment, ?Price $amount = NULL, $remote_id = NULL) {
    $this->assertPaymentState($payment, ['pending']);

    // If not specified, use the entire amount.
    $amount = $amount ?: $payment->getAmount();
    $payment->state = 'completed';
    $payment->setAmount($amount);
    $payment->setRemoteId($remote_id);
    $payment->save();
  }

}
