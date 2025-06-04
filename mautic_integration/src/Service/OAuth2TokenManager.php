<?php

namespace Drupal\mautic_integration\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * OAuth2 Token Manager for Mautic API authentication.
 */
class OAuth2TokenManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The Mautic configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructor.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    StateInterface $state,
    TimeInterface $time,
    LoggerInterface $logger
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->state = $state;
    $this->time = $time;
    $this->logger = $logger;
    $this->config = $this->configFactory->get('mautic_integration.settings');
  }

  /**
   * Get a valid access token.
   *
   * @return string|null
   *   The access token or NULL if unable to obtain one.
   */
  public function getAccessToken() {
    $token_data = $this->state->get('mautic_integration.oauth2_token', []);
    
    // Check if we have a valid token
    if ($this->isTokenValid($token_data)) {
      return $token_data['access_token'];
    }

    // Try to refresh the token if we have a refresh token
    if (!empty($token_data['refresh_token'])) {
      $new_token = $this->refreshToken($token_data['refresh_token']);
      if ($new_token) {
        return $new_token['access_token'];
      }
    }

    // Get a new token using client credentials
    $new_token = $this->getClientCredentialsToken();
    if ($new_token) {
      return $new_token['access_token'];
    }

    return NULL;
  }

  /**
   * Check if the current token is valid.
   *
   * @param array $token_data
   *   The token data array.
   *
   * @return bool
   *   TRUE if the token is valid, FALSE otherwise.
   */
  protected function isTokenValid(array $token_data) {
    if (empty($token_data['access_token']) || empty($token_data['expires_at'])) {
      return FALSE;
    }

    // Add 60 seconds buffer to account for request time
    $current_time = $this->time->getRequestTime();
    return $token_data['expires_at'] > ($current_time + 60);
  }

  /**
   * Refresh an existing token.
   *
   * @param string $refresh_token
   *   The refresh token.
   *
   * @return array|null
   *   The new token data or NULL on failure.
   */
  protected function refreshToken($refresh_token) {
    $mautic_url = $this->config->get('mautic_url');
    $client_id = $this->config->get('client_id');
    $client_secret = $this->config->get('client_secret');

    if (empty($mautic_url) || empty($client_id) || empty($client_secret)) {
      $this->logger->error('Missing OAuth2 configuration for token refresh.');
      return NULL;
    }

    try {
      $response = $this->httpClient->post($mautic_url . '/oauth/v2/token', [
        'form_params' => [
          'client_id' => $client_id,
          'client_secret' => $client_secret,
          'grant_type' => 'refresh_token',
          'refresh_token' => $refresh_token,
        ],
        'timeout' => $this->config->get('api_timeout') ?: 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      
      if (isset($data['access_token'])) {
        $token_data = [
          'access_token' => $data['access_token'],
          'refresh_token' => $data['refresh_token'] ?? $refresh_token,
          'expires_at' => $this->time->getRequestTime() + ($data['expires_in'] ?? 3600),
          'token_type' => $data['token_type'] ?? 'bearer',
        ];

        $this->state->set('mautic_integration.oauth2_token', $token_data);
        
        if ($this->config->get('log_api_calls')) {
          $this->logger->info('OAuth2 token refreshed successfully.');
        }

        return $token_data;
      }
    }
    catch (RequestException $e) {
      $this->logger->error('Failed to refresh OAuth2 token: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Get a new token using client credentials grant.
   *
   * @return array|null
   *   The token data or NULL on failure.
   */
  protected function getClientCredentialsToken() {
    $mautic_url = $this->config->get('mautic_url');
    $client_id = $this->config->get('client_id');
    $client_secret = $this->config->get('client_secret');

    if (empty($mautic_url) || empty($client_id) || empty($client_secret)) {
      $this->logger->error('Missing OAuth2 configuration for client credentials token.');
      return NULL;
    }

    try {
      $response = $this->httpClient->post($mautic_url . '/oauth/v2/token', [
        'form_params' => [
          'client_id' => $client_id,
          'client_secret' => $client_secret,
          'grant_type' => 'client_credentials',
        ],
        'timeout' => $this->config->get('api_timeout') ?: 30,
      ]);

      $data = json_decode($response->getBody()->getContents(), TRUE);
      
      if (isset($data['access_token'])) {
        $token_data = [
          'access_token' => $data['access_token'],
          'refresh_token' => $data['refresh_token'] ?? NULL,
          'expires_at' => $this->time->getRequestTime() + ($data['expires_in'] ?? 3600),
          'token_type' => $data['token_type'] ?? 'bearer',
        ];

        $this->state->set('mautic_integration.oauth2_token', $token_data);
        
        if ($this->config->get('log_api_calls')) {
          $this->logger->info('OAuth2 token obtained using client credentials.');
        }

        return $token_data;
      }
    }
    catch (RequestException $e) {
      $this->logger->error('Failed to get OAuth2 token with client credentials: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Clear stored token data.
   */
  public function clearToken() {
    $this->state->delete('mautic_integration.oauth2_token');
    $this->logger->info('OAuth2 token cleared.');
  }

  /**
   * Get token information for debugging.
   *
   * @return array
   *   Token information without sensitive data.
   */
  public function getTokenInfo() {
    $token_data = $this->state->get('mautic_integration.oauth2_token', []);
    
    if (empty($token_data)) {
      return ['status' => 'No token stored'];
    }

    return [
      'status' => $this->isTokenValid($token_data) ? 'Valid' : 'Expired',
      'expires_at' => !empty($token_data['expires_at']) ? date('Y-m-d H:i:s', $token_data['expires_at']) : 'Unknown',
      'token_type' => $token_data['token_type'] ?? 'Unknown',
      'has_refresh_token' => !empty($token_data['refresh_token']),
    ];
  }

}