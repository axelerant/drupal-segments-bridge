<?php

namespace Drupal\mautic_integration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\mautic_integration\Service\ConfigValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Mautic Integration settings for this site.
 */
class AdminSettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'mautic_integration.settings';

  /**
   * The config validator service.
   *
   * @var \Drupal\mautic_integration\Service\ConfigValidator
   */
  protected $configValidator;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager
   *   The typed configuration manager.
   * @param \Drupal\mautic_integration\Service\ConfigValidator $config_validator
   *   The config validator service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    ConfigValidator $config_validator
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->configValidator = $config_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('mautic_integration.config_validator')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mautic_integration_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    // Basic Connection Settings
    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Mautic Connection Settings'),
      '#open' => TRUE,
    ];

    $form['connection']['mautic_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Mautic Base URL'),
      '#description' => $this->t('Enter your Mautic installation URL (e.g., https://your-mautic.com)'),
      '#default_value' => $config->get('mautic_url'),
      '#required' => TRUE,
    ];

    // Authentication Settings
    $form['authentication'] = [
      '#type' => 'details',
      '#title' => $this->t('Authentication Settings'),
      '#open' => TRUE,
    ];

    $form['authentication']['auth_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Authentication Method'),
      '#description' => $this->t('Choose your preferred authentication method.'),
      '#options' => [
        'oauth2' => $this->t('OAuth2 (Recommended)'),
        'basic' => $this->t('Basic Authentication'),
      ],
      '#default_value' => $config->get('oauth2_enabled') ? 'oauth2' : 'basic',
    ];

    // OAuth2 Settings
    $form['authentication']['oauth2'] = [
      '#type' => 'details',
      '#title' => $this->t('OAuth2 Settings'),
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'oauth2'],
        ],
      ],
    ];

    $form['authentication']['oauth2']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID'),
      '#description' => $this->t('OAuth2 Client ID from your Mautic API credentials.'),
      '#default_value' => $config->get('client_id'),
    ];

    $form['authentication']['oauth2']['client_secret'] = [
      '#type' => 'password',
      '#title' => $this->t('Client Secret'),
      '#description' => $this->t('OAuth2 Client Secret from your Mautic API credentials.'),
      '#default_value' => $config->get('client_secret'),
    ];

    // Basic Auth Settings
    $form['authentication']['basic'] = [
      '#type' => 'details',
      '#title' => $this->t('Basic Authentication Settings'),
      '#states' => [
        'visible' => [
          ':input[name="auth_method"]' => ['value' => 'basic'],
        ],
      ],
    ];

    $form['authentication']['basic']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('Mautic username for API access.'),
      '#default_value' => $config->get('username'),
    ];

    $form['authentication']['basic']['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Mautic password for API access.'),
      '#default_value' => $config->get('password'),
    ];

    // Tracking Settings
    $form['tracking'] = [
      '#type' => 'details',
      '#title' => $this->t('Tracking Settings'),
      '#open' => FALSE,
    ];

    $form['tracking']['tracking_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Mautic Tracking'),
      '#description' => $this->t('Automatically add Mautic tracking script to all pages.'),
      '#default_value' => $config->get('tracking_enabled'),
    ];

    $form['tracking']['tracking_script'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Mautic Tracking Script (Recommended)'),
      '#description' => $this->t('
        <strong>Recommended:</strong> Get your tracking script from your Mautic instance:<br>
        1. Go to your Mautic: <strong>Settings → Configuration → Tracking Settings</strong><br>
        2. Copy the complete JavaScript tracking code<br>
        3. Paste it here<br>
        <br>
        <strong>Alternative:</strong> Leave empty to auto-generate a basic script using your Mautic URL above.<br>
        <br>
        <strong>Script Placement:</strong> Will be placed before the <code>&lt;/body&gt;</code> tag.<br>
        <strong>Current Mautic URL:</strong> <code>@url</code>
      ', ['@url' => $config->get('mautic_url') ?: 'Configure Mautic URL above first']),
      '#default_value' => $config->get('tracking_script'),
      '#rows' => 8,
      '#states' => [
        'visible' => [
          ':input[name="tracking_enabled"]' => ['checked' => TRUE],
        ],
      ],
      '#placeholder' => $this->t('Paste your Mautic tracking script here, or leave empty for auto-generation'),
    ];

    // Performance Settings
    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => FALSE,
    ];

    $form['performance']['form_cache_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Form Caching'),
      '#description' => $this->t('Cache Mautic forms to improve performance.'),
      '#default_value' => $config->get('form_cache_enabled'),
    ];

    $form['performance']['form_cache_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Form Cache Lifetime'),
      '#description' => $this->t('How long to cache forms (in seconds).'),
      '#default_value' => $config->get('form_cache_lifetime'),
      '#min' => 60,
      '#states' => [
        'visible' => [
          ':input[name="form_cache_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['performance']['segment_cache_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Segment Caching'),
      '#description' => $this->t('Cache contact segments to improve performance.'),
      '#default_value' => $config->get('segment_cache_enabled'),
    ];

    $form['performance']['segment_cache_lifetime'] = [
      '#type' => 'number',
      '#title' => $this->t('Segment Cache Lifetime'),
      '#description' => $this->t('How long to cache segments (in seconds).'),
      '#default_value' => $config->get('segment_cache_lifetime'),
      '#min' => 60,
      '#states' => [
        'visible' => [
          ':input[name="segment_cache_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // API Settings
    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API Settings'),
      '#open' => FALSE,
    ];

    $form['api']['api_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout'),
      '#description' => $this->t('Maximum time to wait for API responses (in seconds).'),
      '#default_value' => $config->get('api_timeout'),
      '#min' => 5,
      '#max' => 120,
    ];

    $form['api']['api_retry_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('API Retry Attempts'),
      '#description' => $this->t('Number of times to retry failed API calls.'),
      '#default_value' => $config->get('api_retry_attempts'),
      '#min' => 0,
      '#max' => 10,
    ];

    // Debug Settings
    $form['debug'] = [
      '#type' => 'details',
      '#title' => $this->t('Debug Settings'),
      '#open' => FALSE,
    ];

    $form['debug']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Debug Mode'),
      '#description' => $this->t('Enable detailed logging for troubleshooting. Disable in production.'),
      '#default_value' => $config->get('debug_mode'),
    ];

    $form['debug']['log_api_calls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log API Calls'),
      '#description' => $this->t('Log all API requests and responses for debugging.'),
      '#default_value' => $config->get('log_api_calls'),
    ];

    // Test Connection Button
    $form['test'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Connection'),
      '#open' => FALSE,
    ];

    $form['test']['test_connection'] = [
      '#type' => 'button',
      '#value' => $this->t('Test Mautic Connection'),
      '#ajax' => [
        'callback' => '::testConnection',
        'wrapper' => 'test-results',
      ],
    ];

    $form['test']['test_results'] = [
      '#type' => 'markup',
      '#markup' => '<div id="test-results"></div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Validate Mautic URL
    $mautic_url = $form_state->getValue('mautic_url');
    if (!empty($mautic_url) && !filter_var($mautic_url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('mautic_url', $this->t('Please enter a valid URL.'));
    }

    // Validate authentication based on selected method
    $auth_method = $form_state->getValue('auth_method');
    if ($auth_method === 'oauth2') {
      if (empty($form_state->getValue('client_id'))) {
        $form_state->setErrorByName('client_id', $this->t('Client ID is required for OAuth2 authentication.'));
      }
      if (empty($form_state->getValue('client_secret'))) {
        $form_state->setErrorByName('client_secret', $this->t('Client Secret is required for OAuth2 authentication.'));
      }
    } elseif ($auth_method === 'basic') {
      if (empty($form_state->getValue('username'))) {
        $form_state->setErrorByName('username', $this->t('Username is required for Basic authentication.'));
      }
      if (empty($form_state->getValue('password'))) {
        $form_state->setErrorByName('password', $this->t('Password is required for Basic authentication.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $auth_method = $form_state->getValue('auth_method');
    
    // Save configuration
    $this->config(static::SETTINGS)
      ->set('mautic_url', rtrim($form_state->getValue('mautic_url'), '/'))
      ->set('oauth2_enabled', $auth_method === 'oauth2')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('basic_auth_enabled', $auth_method === 'basic')
      ->set('username', $form_state->getValue('username'))
      ->set('password', $form_state->getValue('password'))
      ->set('tracking_enabled', $form_state->getValue('tracking_enabled'))
      ->set('tracking_script', $form_state->getValue('tracking_script'))
      ->set('form_cache_enabled', $form_state->getValue('form_cache_enabled'))
      ->set('form_cache_lifetime', $form_state->getValue('form_cache_lifetime'))
      ->set('segment_cache_enabled', $form_state->getValue('segment_cache_enabled'))
      ->set('segment_cache_lifetime', $form_state->getValue('segment_cache_lifetime'))
      ->set('api_timeout', $form_state->getValue('api_timeout'))
      ->set('api_retry_attempts', $form_state->getValue('api_retry_attempts'))
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->set('log_api_calls', $form_state->getValue('log_api_calls'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * AJAX callback for testing Mautic connection.
   */
  public function testConnection(array &$form, FormStateInterface $form_state) {
    try {
      $results = $this->configValidator->validateConfiguration();
      
      $message_type = $results['success'] ? 'status' : 'error';
      $message = $results['success'] ? 
        $this->t('✅ Connection successful!') : 
        $this->t('❌ Connection failed.');

      $details = [];
      if (!empty($results['errors'])) {
        $details[] = '<strong>Errors:</strong><ul><li>' . implode('</li><li>', $results['errors']) . '</li></ul>';
      }
      if (!empty($results['warnings'])) {
        $details[] = '<strong>Warnings:</strong><ul><li>' . implode('</li><li>', $results['warnings']) . '</li></ul>';
      }
      if (!empty($results['info'])) {
        $details[] = '<strong>Info:</strong><ul><li>' . implode('</li><li>', $results['info']) . '</li></ul>';
      }

      $markup = '<div class="messages messages--' . $message_type . '">' . $message . '</div>';
      if (!empty($details)) {
        $markup .= '<div style="margin-top: 10px;">' . implode('', $details) . '</div>';
      }

      $response = [
        '#type' => 'markup',
        '#markup' => $markup,
      ];
    }
    catch (\Exception $e) {
      $response = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--error">' . 
          $this->t('Test failed: @error', ['@error' => $e->getMessage()]) . 
          '</div>',
      ];
    }

    return $response;
  }

}