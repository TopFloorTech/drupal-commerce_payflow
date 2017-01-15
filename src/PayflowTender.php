<?php

namespace Drupal\commerce_payflow;

/**
 * Defines the supported values for the "TENDER" parameter.
 */
final class PayflowTender {
  const ACH = 'A';

  const CREDIT_CARD = 'C';

  const PINLESS_DEBIT = 'D';

  const TELECHECK = 'K';

  const PAYPAL = 'P';
}
