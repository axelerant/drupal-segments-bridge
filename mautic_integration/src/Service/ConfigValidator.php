<?php

namespace Drupal\mautic_integration\Service;

use Psr\Log\LoggerInterface;

/**
 * Configuration validator service for Mautic Integration.
 */
class ConfigValidator {

  /**
   * The Mautic API client.
   *
   * @var \Drupal\mautic_integration\Service\MauticApiClient
   */
  protected $apiClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(
    MauticApiClient $api_client,
    LoggerInterface $logger
  ) {
    $this->apiClient = $api_client;
    $this->logger = $logger;
  }

  /**
   * Validate the complete Mautic configuration.
   *
   * @return array
   *   Validation results with success status and messages.
   */
  public function validateConfiguration() {
    $results = [
      'success' => TRUE,
      'errors' => [],
      'warnings' => [],
      'info' => [],
    ];

    // Test API connection
    $connection_test = $this->apiClient->testConnection();
    if (!$connection_test['success']) {
      $results['success'] = FALSE;
      $results['errors'][] = 'API Connection Failed: ' . $connection_test['message'];
    }
    else {
      $results['info'][] = 'API Connection: ' . $connection_test['message'];
      
      // Add connection details
      if (!empty($connection_test['details'])) {
        foreach ($connection_test['details'] as $key => $value) {
          if (is_array($value)) {
            $results['info'][] = ucfirst(str_replace('_', ' ', $key)) . ': ' . json_encode($value);
          }
          else {
            $results['info'][] = ucfirst(str_replace('_', ' ', $key)) . ': ' . $value;
          }
        }
      }
    }

    // Test forms access
    $forms_test = $this->validateFormsAccess();
    if (!$forms_test['success']) {
      $results['errors'][] = $forms_test['message'];
      $results['success'] = FALSE;
    }
    else {
      $results['info'][] = $forms_test['message'];
    }

    // Test segments access
    $segments_test = $this->validateSegmentsAccess();
    if (!$segments_test['success']) {
      $results['warnings'][] = $segments_test['message'];
    }
    else {
      $results['info'][] = $segments_test['message'];
    }

    return $results;
  }

  /**
   * Validate forms access.
   *
   * @return array
   *   Validation result for forms access.
   */
  protected function validateFormsAccess() {
    try {
      $forms = $this->apiClient->getForms(['limit' => 1]);
      
      if ($forms === NULL) {
        return [
          'success' => FALSE,
          'message' => 'Unable to access Mautic forms. Check API permissions.',
        ];
      }

      $total_forms = $forms['total'] ?? 0;
      return [
        'success' => TRUE,
        'message' => 'Forms Access: Found ' . $total_forms . ' forms',
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Forms validation failed: @error', ['@error' => $e->getMessage()]);
      return [
        'success' => FALSE,
        'message' => 'Forms validation failed: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Validate segments access.
   *
   * @return array
   *   Validation result for segments access.
   */
  protected function validateSegmentsAccess() {
    try {
      $segments = $this->apiClient->getSegments(['limit' => 1]);
      
      if ($segments === NULL) {
        return [
          'success' => FALSE,
          'message' => 'Unable to access Mautic segments. Check API permissions.',
        ];
      }

      $total_segments = $segments['total'] ?? 0;
      return [
        'success' => TRUE,
        'message' => 'Segments Access: Found ' . $total_segments . ' segments',
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Segments validation failed: @error', ['@error' => $e->getMessage()]);
      return [
        'success' => FALSE,
        'message' => 'Segments validation failed: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Validate tracking script configuration.
   *
   * @param string $tracking_script
   *   The tracking script to validate.
   *
   * @return array
   *   Validation results.
   */
  public function validateTrackingScript($tracking_script) {
    $results = [
      'success' => TRUE,
      'errors' => [],
      'warnings' => [],
    ];

    if (empty($tracking_script)) {
      $results['warnings'][] = 'Tracking script is empty. User tracking will not work.';
      return $results;
    }

    // Check for basic script structure
    if (strpos($tracking_script, '<script') === FALSE) {
      $results['errors'][] = 'Tracking script does not contain script tags.';
      $results['success'] = FALSE;
    }

    // Check for Mautic tracking patterns
    $mautic_patterns = [
      'mt(\'send\'',
      'mauticTrackingPixel',
      'mautic_tracking_pixel',
      'trackingPixel',
    ];

    $has_mautic_pattern = FALSE;
    foreach ($mautic_patterns as $pattern) {
      if (strpos($tracking_script, $pattern) !== FALSE) {
        $has_mautic_pattern = TRUE;
        break;
      }
    }

    if (!$has_mautic_pattern) {
      $results['warnings'][] = 'Tracking script does not appear to contain Mautic tracking code.';
    }

    // Check for potential security issues
    $dangerous_patterns = [
      'eval(',
      'document.write(',
      'innerHTML',
      'outerHTML',
    ];

    foreach ($dangerous_patterns as $pattern) {
      if (stripos($tracking_script, $pattern) !== FALSE) {
        $results['warnings'][] = 'Tracking script contains potentially unsafe code: ' . $pattern;
      }
    }

    return $results;
  }

  /**
   * Validate OAuth2 configuration.
   *
   * @param array $config_values
   *   Configuration values to validate.
   *
   * @return array
   *   Validation results.
   */
  public function validateOAuth2Config(array $config_values) {
    $results = [
      'success' => TRUE,
      'errors' => [],
      'warnings' => [],
    ];

    if (empty($config_values['client_id'])) {
      $results['errors'][] = 'OAuth2 Client ID is required.';
      $results['success'] = FALSE;
    }

    if (empty($config_values['client_secret'])) {
      $results['errors'][] = 'OAuth2 Client Secret is required.';
      $results['success'] = FALSE;
    }

    // Validate Client ID format (basic check)
    if (!empty($config_values['client_id']) && !preg_match('/^[a-zA-Z0-9_-]+$/', $config_values['client_id'])) {
      $results['warnings'][] = 'Client ID format may be invalid. Expected alphanumeric characters, underscores, and dashes only.';
    }

    return $results;
  }

  /**
   * Validate Basic Auth configuration.
   *
   * @param array $config_values
   *   Configuration values to validate.
   *
   * @return array
   *   Validation results.
   */
  public function validateBasicAuthConfig(array $config_values) {
    $results = [
      'success' => TRUE,
      'errors' => [],
      'warnings' => [],
    ];

    if (empty($config_values['username'])) {
      $results['errors'][] = 'Username is required for Basic Authentication.';
      $results['success'] = FALSE;
    }

    if (empty($config_values['password'])) {
      $results['errors'][] = 'Password is required for Basic Authentication.';
      $results['success'] = FALSE;
    }

    // Basic security check
    if (!empty($config_values['username']) && strlen($config_values['username']) < 3) {
      $results['warnings'][] = 'Username appears to be very short.';
    }

    if (!empty($config_values['password']) && strlen($config_values['password']) < 8) {
      $results['warnings'][] = 'Password should be at least 8 characters long for security.';
    }

    return $results;
  }

  /**
   * Validate Mautic URL.
   *
   * @param string $url
   *   The URL to validate.
   *
   * @return array
   *   Validation results.
   */
  public function validateMauticUrl($url) {
    $results = [
      'success' => TRUE,
      'errors' => [],
      'warnings' => [],
    ];

    if (empty($url)) {
      $results['errors'][] = 'Mautic URL is required.';
      $results['success'] = FALSE;
      return $results;
    }

    // Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
      $results['errors'][] = 'Invalid URL format.';
      $results['success'] = FALSE;
      return $results;
    }

    // Check for HTTPS (recommended)
    if (strpos($url, 'https://') !== 0) {
      $results['warnings'][] = 'HTTPS is recommended for secure API communication.';
    }

    // Check for common URL issues
    if (substr($url, -1) === '/') {
      $results['warnings'][] = 'URL should not end with a trailing slash.';
    }

    return $results;
  }

}