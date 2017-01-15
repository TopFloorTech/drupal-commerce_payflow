<?php

namespace Drupal\commerce_payflow;

/**
 * Defines the supported types of transactions for Commerce PayFlow.
 */
final class PayflowTransactionType {
  const AUTHORIZATION = 'A';

  const BALANCE_INQUIRY = 'B';

  const CREDIT = 'C';

  const DELAYED_CAPTURE = 'D';

  const VOICE_AUTHORIZATION = 'F';

  const INQUIRY = 'I';

  const RATE_LOOKUP = 'K';

  const DATA_UPLOAD = 'L';

  const DUPLICATE_TRANSACTION = 'N';

  const SALE = 'S';

  const VOID = 'V';
}
