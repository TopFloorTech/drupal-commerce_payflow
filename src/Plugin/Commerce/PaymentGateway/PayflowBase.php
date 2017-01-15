<?php

namespace Drupal\commerce_payflow\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payflow\PayflowTender;
use Drupal\commerce_payflow\PaymentState;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\HardDeclineException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OnsitePaymentGatewayBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an abstract base class for the Payflow gateway.
 */
abstract class PayflowBase extends OnsitePaymentGatewayBase implements PayflowInterface {

  /**
   * Payflow test API URL.
   */
  const PAYPAL_API_TEST_URL = 'https://pilot-payflowpro.paypal.com';

  /**
   * Payflow production API URL.
   */
  const PAYPAL_API_URL = 'https://payflowpro.paypal.com';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * State service for retrieving database info.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, ClientInterface $client, StateInterface $state) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entity_type_manager,
      $payment_type_manager,
      $payment_method_type_manager
    );

    $this->httpClient = $client;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var EntityTypeManagerInterface $entityTypeManager */
    $entityTypeManager = $container->get('entity_type.manager');

    /** @var PaymentTypeManager $paymentTypeManager */
    $paymentTypeManager = $container->get('plugin.manager.commerce_payment_type');

    /** @var PaymentMethodTypeManager $paymentMethodTypeManager */
    $paymentMethodTypeManager = $container->get('plugin.manager.commerce_payment_method_type');

    /** @var ClientInterface $httpClient */
    $httpClient = $container->get('http_client');

    /** @var StateInterface $state */
    $state = $container->get('state');

    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager,
      $paymentTypeManager,
      $paymentMethodTypeManager,
      $httpClient,
      $state
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = [
      'partner' => '',
      'vendor' => '',
      'user' => '',
      'password' => '',
    ];

    return $config + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['partner'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Partner'),
      '#default_value' => $this->configuration['partner'],
      '#required' => TRUE,
    ];

    $form['vendor'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vendor'),
      '#default_value' => $this->configuration['vendor'],
      '#required' => TRUE,
    ];

    $form['user'] = [
      '#type' => 'textfield',
      '#title' => $this->t('User'),
      '#default_value' => $this->configuration['user'],
      '#required' => TRUE,
    ];

    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Only needed if you wish to change the stored value.'),
      '#default_value' => $this->configuration['password'],
      '#required' => FALSE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);

      $this->configuration['partner'] = $values['partner'];
      $this->configuration['vendor'] = $values['vendor'];
      $this->configuration['user'] = $values['user'];

      if (!empty($values['password'])) {
        $this->configuration['password'] = $values['password'];
      }
    }
  }

  /**
   * Returns the Api URL.
   */
  protected function getApiUrl() {
    return $this->getMode() == 'test' ? self::PAYPAL_API_TEST_URL : self::PAYPAL_API_URL;
  }

  /**
   * Returns the partner.
   */
  protected function getPartner() {
    return $this->configuration['partner'] ?: '';
  }

  /**
   * Returns the vendor.
   */
  protected function getVendor() {
    return $this->configuration['vendor'] ?: '';
  }

  /**
   * Returns the user.
   */
  protected function getUser() {
    return $this->configuration['user'] ?: '';
  }

  /**
   * Returns the password.
   */
  protected function getPassword() {
    return $this->configuration['password'] ?: '';
  }

  /**
   * Format the expiration date for Payflow from the provided payment details.
   *
   * @param array $payment_details
   *   The payment details array.
   *
   * @return string
   *   The expiration date string.
   */
  protected function getExpirationDate(array $payment_details) {
    $date = $payment_details['expiration']['month'] . '/01/' . $payment_details['expiration']['year'];

    return date('my', strtotime($date));
  }

  /**
   * Merge default Payflow parameters in with the provided ones.
   * @param array $parameters
   *   The parameters for the transaction.
   *
   * @return array
   *   The new parameters.
   */
  protected function getParameters(array $parameters = []) {
    $defaultParameters = [
      'tender' => PayflowTender::CREDIT_CARD,
      'partner' => $this->getPartner(),
      'vendor' => $this->getVendor(),
      'user' => $this->getUser(),
      'pwd' => $this->getPassword(),
    ];

    return $parameters + $defaultParameters;
  }

  protected function prepareBody(array $parameters = []) {
    $parameters = $this->getParameters($parameters);

    dpm($parameters);

    $values = [];
    foreach ($parameters as $key => $value) {
      $values[] = strtoupper($key) . '=' . $value;
    }

    return implode('&', $values);
  }

  protected function prepareResult($body) {
    $responseParts = explode('&', $body);

    $result = [];
    foreach ($responseParts as $bodyPart) {
      list($key, $value) = explode('=', $bodyPart);

      $result[strtolower($key)] = $value;
    }

    dpm($result);

    return $result;
  }

  /**
   * Post a transaction to the Payflow server and return the response.
   *
   * @param array $parameters
   *   The parameters to send (will have base parameters added).
   *
   * @return array
   *   The response body data in array format.
   */
  protected function executeTransaction(array $parameters) {
    $body = $this->prepareBody($parameters);

    $response = $this->httpClient->post($this->getApiUrl(), [
      'headers' => [
        'Content-Type' => 'text/namevalue',
        'Content-Length' => strlen($body),
      ],
      'body' => $body,
      'timeout' => 0,
    ]);

    return $this->prepareResult($response->getBody());
  }

  /**
   * Attempt to validate payment information according to a payment state.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment to validate.
   *
   * @param string|NULL $paymentState
   *   The payment state to validate the payment for.
   */
  protected function validatePayment(PaymentInterface $payment, $paymentState = NULL) {
    if (is_null($paymentState)) {
      $paymentState = PaymentState::NEW;
    }

    if ($payment->getState()->value != $paymentState) {
      throw new InvalidArgumentException('The provided payment is in an invalid state.');
    }

    $payment_method = $payment->getPaymentMethod();

    if (empty($payment_method)) {
      throw new InvalidArgumentException('The provided payment has no payment method referenced.');
    }

    switch ($paymentState) {
      case Paymentstate::NEW:
        if (REQUEST_TIME >= $payment_method->getExpiresTime()) {
          throw new HardDeclineException('The provided payment method has expired.');
        }

        break;
      case PaymentState::AUTHORIZATION:
        if ($payment->getAuthorizationExpiresTime() < REQUEST_TIME) {
          throw new \InvalidArgumentException('Authorizations are guaranteed for up to 29 days.');
        }

        if (empty($payment->getRemoteId())) {
          throw new \InvalidArgumentException('Could not retrieve the transaction ID.');
        }
        break;
    }
  }
}
