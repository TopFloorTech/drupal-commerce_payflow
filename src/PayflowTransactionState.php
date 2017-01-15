<?php

namespace Drupal\commerce_payflow;

/**
 * Defines the supported types of transactions for Commerce PayFlow.
 */
final class PayflowTransactionState {
  const ACCOUNT_VERIFICATION = 0;

  const GENERAL_ERROR = 1;

  const AUTHORIZATION_APPROVED = 3;

  const PARTIAL_CAPTURE = 4;

  const SETTLEMENT_PENDING = 6;

  const SETTLEMENT_IN_PROGRESS = 7;

  const SETTLED_SUCCESSFULLY = 8;

  const AUTHORIZATION_CAPTURED = 9;

  const CAPTURE_FAILED = 10;

  const FAILED_TO_SETTLE = 11;

  const INCORRECT_ACCOUNT_INFORMATION = 12;

  const BATCH_FAILED = 14;

  const CHARGE_BACK = 15;

  const ACH_FAILED = 16;

  const UNKNOWN_STATUS = 106;

  const ON_HOLD = 206;
}
