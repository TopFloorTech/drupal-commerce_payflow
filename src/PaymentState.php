<?php

namespace Drupal\commerce_payflow;

/**
 * Defines the supported values for the "TENDER" parameter.
 */
final class PaymentState {
  const NEW = 'new';

  const AUTHORIZATION = 'authorization';

  const AUTHORIZATION_VOIDED = 'authorization_voided';

  const CAPTURE_COMPLETED = 'capture_completed';

  const CAPTURE_PARTIALLY_REFUNDED = 'capture_partially_refunded';

  const CAPTURE_REFUNDED = 'capture_refunded';

  const COMPLETED = 'completed';
}
