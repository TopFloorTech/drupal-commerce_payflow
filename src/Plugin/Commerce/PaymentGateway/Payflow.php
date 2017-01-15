<?php

namespace Drupal\commerce_payflow\Plugin\Commerce\PaymentGateway;

use Drupal\address\AddressInterface;
use Drupal\commerce_payflow\PayflowResultCode;
use Drupal\commerce_payflow\PayflowTransactionState;
use Drupal\commerce_payflow\PayflowVerbosity;
use Drupal\commerce_payflow\PaymentGatewayMode;
use Drupal\commerce_payflow\PaymentState;
use Drupal\commerce_payflow\PayflowTransactionType;
use Drupal\commerce_payment\Annotation\CommercePaymentGateway;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;

/**
 * Provides the PayPal Payflow payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "paypal_payflow",
 *   label = "PayPal (Payflow)",
 *   display_label = "Credit Card",
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "amex", "discover", "mastercard", "visa",
 *   },
 * )
 */
class Payflow extends PayflowBase {

  /**
   * {@inheritdoc}
   */
  public function createPayment(PaymentInterface $payment, $capture = TRUE) {
    $this->validatePayment($payment, PaymentState::NEW);

    try {
      $data = $this->executeTransaction([
        'trxtype' => $capture ? PayflowTransactionType::SALE : PayflowTransactionType::AUTHORIZATION,
        'amt' => round($payment->getAmount()->getNumber(), 2),
        'currencycode' => $payment->getAmount()->getCurrencyCode(),
        'origid' => $payment->getPaymentMethod()->getRemoteId(),
        'verbosity' => 'HIGH',
        //'orderid' => $payment->getOrderId(),
      ]);

      if ($data['result'] != PayflowResultCode::APPROVED) {
        throw new HardDeclineException('Could not charge the payment method. Response: ' . $data['respmsg'], $data['result']);
      }

      $payment->state = $capture ? PaymentState::CAPTURE_COMPLETED : PaymentState::AUTHORIZATION;

      if ($capture) {
        $payment->setCapturedTime(REQUEST_TIME);
      } else {
        $payment->setAuthorizationExpiresTime(REQUEST_TIME + (86400 * 29));
      }

      $payment
        ->setTest(($this->getMode() == PaymentGatewayMode::TEST))
        ->setRemoteId($data['pnref'])
        ->setRemoteState(PayflowTransactionState::AUTHORIZATION_APPROVED)
        ->setAuthorizedTime(REQUEST_TIME)
        ->save();
    } catch (RequestException $e) {
      throw new HardDeclineException('Could not charge the payment method.');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function capturePayment(PaymentInterface $payment, Price $amount = NULL) {
    $this->validatePayment($payment, PaymentState::AUTHORIZATION);

    // If not specified, capture the entire amount.
    $amount = $amount ?: $payment->getAmount();

    try {
      $data = $this->executeTransaction([
        'trxtype' => PayflowTransactionType::DELAYED_CAPTURE,
        'amt' => round($amount->getNumber(), 2),
        'currency' => $amount->getCurrencyCode(),
        'origid' => $payment->getRemoteId(),
      ]);

      if ($data['result'] != PayflowResultCode::APPROVED) {
        throw new PaymentGatewayException('Count not capture payment. Message: ' . $data['respmsg'], $data['result']);
      }

      $payment->state = Paymentstate::CAPTURE_COMPLETED;
      $payment
        ->setAmount($amount)
        ->setCapturedTime(REQUEST_TIME)
        ->save();
    } catch (RequestException $e) {
      throw new PaymentGatewayException('Count not capture payment. Message: ' . $e->getMessage(), $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function voidPayment(PaymentInterface $payment) {
    $this->validatePayment($payment, PaymentState::AUTHORIZATION);

    $remoteId = $payment->getRemoteId();

    if (empty($remoteId)) {
      throw new PaymentGatewayException('Remote authorization ID could not be determined.');
    }

    try {
      $data = $this->executeTransaction([
        'trxtype' => PayflowTransactionType::VOID,
        'origid' => $payment->getRemoteId(),
        'verbosity' => PayflowVerbosity::HIGH,
      ]);

      if ($data['result'] != PayflowResultCode::APPROVED) {
        throw new PaymentGatewayException('Payment could not be voided. Message: ' . $data['respmsg'], $data['result']);
      }

      $payment->state = PaymentState::AUTHORIZATION_VOIDED;
      $payment->save();
    } catch (RequestException $e) {
      throw new InvalidArgumentException('Only payments in the "authorization" state can be voided.');
    }
  }

  /**
   * {@inheritdoc}
   *
   * TODO: Find a way to store the capture ID.
   */
  public function refundPayment(PaymentInterface $payment, Price $amount = NULL) {
    if (!in_array($payment->getState()->value, [
      PaymentState::CAPTURE_COMPLETED,
      PaymentState::CAPTURE_PARTIALLY_REFUNDED,
    ])) {
      throw new InvalidArgumentException('Only payments in the "capture_completed" and "capture_partially_refunded" states can be refunded.');
    }

    if ($payment->getCapturedTime() < strtotime('-180 days')) {
      throw new InvalidRequestException("Unable to refund a payment captured more than 180 days ago.");
    }

    // If not specified, refund the entire amount.
    $amount = $amount ?: $payment->getAmount();

    if ($amount->greaterThan($payment->getBalance())) {
      throw new InvalidRequestException(sprintf("Can't refund more than %s.", $payment->getBalance()->__toString()));
    }

    if (empty($payment->getRemoteId())) {
      throw new InvalidRequestException('Could not determine the remote payment details.');
    }

    try {
      $new_refunded_amount = $payment->getRefundedAmount()->add($amount);

      $data = $this->executeTransaction([
        'trxtype' => PayflowTransactionType::CREDIT,
        'origid' => $payment->getRemoteId(),
      ]);

      if ($data['result'] != PayflowResultCode::APPROVED) {
        throw new PaymentGatewayException('Credit could not be completed. Message: ' . $data['respmsg'], $data['result']);
      }

      $payment->state = ($new_refunded_amount->lessThan($payment->getAmount()))
        ? PaymentState::CAPTURE_PARTIALLY_REFUNDED
        : PaymentState::CAPTURE_REFUNDED;

      $payment
        ->setRefundedAmount($new_refunded_amount)
        ->save();
    } catch (RequestException $e) {
      throw new InvalidRequestException("Could not refund the payment.", $e->getCode(), $e);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    try {
      /** @var AddressInterface $address */
      $address = $payment_method->getBillingProfile()->get('address')->first();

      $data = $this->executeTransaction([
        'trxtype' => PayflowTransactionType::AUTHORIZATION,
        'amt' => 0,
        'verbosity' => PayflowVerbosity::HIGH,
        'acct' => $payment_details['number'],
        'expdate' => $this->getExpirationDate($payment_details),
        'cvv2' => $payment_details['security_code'],
        'billtoemail' => $payment_method->getOwner()->getEmail(),
        'billtofirstname' => $address->getGivenName(),
        'billtolastname' => $address->getFamilyName(),
        'billtostreet' => $address->getAddressLine1(),
        'billtocity' => $address->getLocality(),
        'billtostate' => $address->getAdministrativeArea(),
        'billtozip' => $address->getPostalCode(),
        'billtocountry' => $address->getCountryCode(),
      ]);

      $allowed = [
        PayflowResultCode::APPROVED,
        PayflowResultCode::FLAGGED_FOR_REVIEW_BY_FRAUD_FILTERS,
      ];

     // if (!in_array($data['result'], $allowed) || $data['respmsg'] != 'Verified') {
     if (!in_array($data['result'], $allowed)) {
        throw new HardDeclineException("Unable to verify the credit card.", $data['result']);
      }

      $payment_method->card_type = $payment_details['type'];
      // Only the last 4 numbers are safe to store.
      $payment_method->card_number = substr($payment_details['number'], -4);
      $payment_method->card_exp_month = $payment_details['expiration']['month'];
      $payment_method->card_exp_year = $payment_details['expiration']['year'];
      $expires = CreditCard::calculateExpirationTimestamp($payment_details['expiration']['month'], $payment_details['expiration']['year']);

      // Store the remote ID returned by the request.
      $payment_method
        ->setRemoteId($data['pnref'])
        ->setExpiresTime($expires)
        ->save();
    } catch (RequestException $e) {
      throw new HardDeclineException("Unable to store the credit card");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    $payment_method->delete();
  }
}
