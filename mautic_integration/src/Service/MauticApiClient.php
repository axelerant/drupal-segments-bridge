<?php

namespace Drupal\mautic_integration\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Mautic API Client service.
 */
class MauticApiClient {

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
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * The OAuth2 token manager.
   *
   * @var \Drupal\mautic_integration\Service\OAuth2TokenManager
   */
  protected $tokenManager;

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
    LoggerInterface $logger,
    CacheBackendInterface $cache_backend,
    OAuth2TokenManager $token_manager
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger;
    $this->cacheBackend = $cache_backend;
    $this->tokenManager = $token_manager;
    $this->config = $this->configFactory->get('mautic_integration.settings');
  }

  /**
   * Make an authenticated API request to Mautic.
   *
   * @param string $method
   *   The HTTP method (GET, POST, PUT, DELETE).
   * @param string $endpoint
   *   The API endpoint (e.g., 'contacts', 'forms').
   * @param array $options
   *   Additional request options.
   * @param bool $use_cache
   *   Whether to use cache for GET requests.
   *
   * @return array|null
   *   The API response data or NULL on failure.
   */
  public function request($method, $endpoint, array $options = [], $use_cache = TRUE) {
    $mautic_url = $this->config->get('mautic_url');
    
    if (empty($mautic_url)) {
      $this->logger->error('Mautic URL not configured.');
      return NULL;
    }

    // Check cache for GET requests
    if ($method === 'GET' && $use_cache) {
      $cache_key = 'mautic_api:' . md5($endpoint . serialize($options));
      $cached = $this->cacheBackend->get($cache_key);
      if ($cached && $cached->valid) {
        return $cached->data;
      }
    }

    // Prepare request headers
    $headers = $this->getAuthHeaders();
    if (!$headers) {
      $this->logger->error('Unable to get authentication headers.');
      return NULL;
    }

    // Merge with provided options
    $request_options = array_merge([
      'headers' => $headers,
      'timeout' => $this->config->get('api_timeout') ?: 30,
    ], $options);

    $url = $mautic_url . '/api/' . ltrim($endpoint, '/');
    

    try {
      $response = $this->httpClient->request($method, $url, $request_options);
      $data = json_decode($response->getBody()->getContents(), TRUE);

      if ($this->config->get('log_api_calls')) {
        $this->logger->info('Mautic API @method @endpoint: @status', [
          '@method' => $method,
          '@endpoint' => $endpoint,
          '@status' => $response->getStatusCode(),
        ]);
      }

      // Cache successful GET requests
      if ($method === 'GET' && $use_cache && isset($cache_key)) {
        $cache_lifetime = $this->getCacheLifetime($endpoint);
        $this->cacheBackend->set($cache_key, $data, time() + $cache_lifetime);
      }

      return $data;
    }
    catch (RequestException $e) {
      $this->logger->error('Mautic API @method @endpoint failed: @error', [
        '@method' => $method,
        '@endpoint' => $endpoint,
        '@error' => $e->getMessage(),
      ]);

      // Try to retry the request if configured
      if ($this->shouldRetry($e)) {
        return $this->retryRequest($method, $endpoint, $options, $use_cache);
      }

      return NULL;
    }
  }

  /**
   * Get authentication headers for API requests.
   *
   * @return array|null
   *   The headers array or NULL if authentication fails.
   */
  protected function getAuthHeaders() {
    if ($this->config->get('oauth2_enabled')) {
      return $this->getOAuth2Headers();
    }
    elseif ($this->config->get('basic_auth_enabled')) {
      return $this->getBasicAuthHeaders();
    }

    $this->logger->error('No authentication method configured.');
    return NULL;
  }

  /**
   * Get OAuth2 authentication headers.
   *
   * @return array|null
   *   The headers array or NULL if token is unavailable.
   */
  protected function getOAuth2Headers() {
    $token = $this->tokenManager->getAccessToken();
    
    if (!$token) {
      $this->logger->error('Unable to obtain OAuth2 access token.');
      return NULL;
    }

    return [
      'Authorization' => 'Bearer ' . $token,
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];
  }

  /**
   * Get Basic authentication headers.
   *
   * @return array|null
   *   The headers array or NULL if credentials are missing.
   */
  protected function getBasicAuthHeaders() {
    $username = $this->config->get('username');
    $password = $this->config->get('password');

    if (empty($username) || empty($password)) {
      $this->logger->error('Basic authentication credentials not configured.');
      return NULL;
    }

    return [
      'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];
  }

  /**
   * Test the API connection.
   *
   * @return array
   *   Connection test results.
   */
  public function testConnection() {
    $results = [
      'success' => FALSE,
      'message' => '',
      'details' => [],
    ];

    // Test basic configuration
    $mautic_url = $this->config->get('mautic_url');
    if (empty($mautic_url)) {
      $results['message'] = 'Mautic URL is not configured.';
      return $results;
    }

    // Test URL accessibility
    try {
      $response = $this->httpClient->get($mautic_url, ['timeout' => 10]);
      if ($response->getStatusCode() !== 200) {
        $results['message'] = 'Mautic URL is not accessible.';
        return $results;
      }
    }
    catch (RequestException $e) {
      $results['message'] = 'Cannot connect to Mautic URL: ' . $e->getMessage();
      return $results;
    }

    // Test API authentication
    $api_response = $this->request('GET', 'contacts', ['query' => ['limit' => 1]]);
    $this->logger->error('testing.'. json_encode($array));
    if ($api_response === NULL) {
      $results['message'] = 'API authentication failed.';
      if ($this->config->get('oauth2_enabled')) {
        $results['details']['token_info'] = $this->tokenManager->getTokenInfo();
      }
      return $results;
    }

    $results['success'] = TRUE;
    $results['message'] = 'Connection successful!';
    $results['details'] = [
      'mautic_url' => $mautic_url,
      'auth_method' => $this->config->get('oauth2_enabled') ? 'OAuth2' : 'Basic Auth',
      'api_response' => isset($api_response['total']) ? 'Found ' . $api_response['total'] . ' contacts' : 'API responding',
    ];

    if ($this->config->get('oauth2_enabled')) {
      $results['details']['token_info'] = $this->tokenManager->getTokenInfo();
    }

    return $results;
  }

  /**
   * Get contacts from Mautic.
   *
   * @param array $params
   *   Query parameters.
   *
   * @return array|null
   *   Contacts data or NULL on failure.
   */
  public function getContacts(array $params = []) {
    return $this->request('GET', 'contacts', ['query' => $params]);
  }

  /**
   * Get a specific contact by ID.
   *
   * @param int $contact_id
   *   The contact ID.
   *
   * @return array|null
   *   Contact data or NULL on failure.
   */
  public function getContact($contact_id) {
    return $this->request('GET', 'contacts/' . $contact_id);
  }

  /**
   * Get forms from Mautic.
   *
   * @param array $params
   *   Query parameters.
   *
   * @return array|null
   *   Forms data or NULL on failure.
   */
  public function getForms(array $params = []) {
    $cache_enabled = $this->config->get('form_cache_enabled');
    return $this->request('GET', 'forms', ['query' => $params], $cache_enabled);
  }

  /**
   * Get a specific form by ID.
   *
   * @param int $form_id
   *   The form ID.
   *
   * @return array|null
   *   Form data or NULL on failure.
   */
  public function getForm($form_id) {
    $cache_enabled = $this->config->get('form_cache_enabled');
    return $this->request('GET', 'forms/' . $form_id, [], $cache_enabled);
  }

  /**
   * Get segments from Mautic.
   *
   * @param array $params
   *   Query parameters.
   *
   * @return array|null
   *   Segments data or NULL on failure.
   */
  public function getSegments(array $params = []) {
    $cache_enabled = $this->config->get('segment_cache_enabled');
    return $this->request('GET', 'segments', ['query' => $params], $cache_enabled);
  }

  /**
   * Get segments from Mautic.
   *
   * @param int $contact_id
   *   Query parameters.
   *
   * @return array|null
   *   Segments data or NULL on failure.
   */
  public function getContactSegments(int $contact_id) {
    $cache_enabled = $this->config->get('segment_cache_enabled');
    return $this->request('GET', 'contacts/'. $contact_id .'/segments', ['query' => NULL], $cache_enabled);
  }

  /**
   * Get cache lifetime for a specific endpoint.
   *
   * @param string $endpoint
   *   The API endpoint.
   *
   * @return int
   *   Cache lifetime in seconds.
   */
  protected function getCacheLifetime($endpoint) {
    if (strpos($endpoint, 'forms') === 0) {
      return $this->config->get('form_cache_lifetime') ?: 3600;
    }
    elseif (strpos($endpoint, 'segments') === 0) {
      return $this->config->get('segment_cache_lifetime') ?: 300;
    }

    return 300; // Default 5 minutes
  }

  /**
   * Determine if a request should be retried.
   *
   * @param \GuzzleHttp\Exception\RequestException $exception
   *   The request exception.
   *
   * @return bool
   *   TRUE if the request should be retried.
   */
  protected function shouldRetry(RequestException $exception) {
    $retry_attempts = $this->config->get('api_retry_attempts') ?: 0;
    
    if ($retry_attempts <= 0) {
      return FALSE;
    }

    // Retry on server errors (5xx) and timeouts
    if ($exception->hasResponse()) {
      $status_code = $exception->getResponse()->getStatusCode();
      return $status_code >= 500;
    }

    // Retry on connection timeouts
    return strpos($exception->getMessage(), 'timeout') !== FALSE;
  }

  /**
   * Retry a failed request.
   *
   * @param string $method
   *   The HTTP method.
   * @param string $endpoint
   *   The API endpoint.
   * @param array $options
   *   Request options.
   * @param bool $use_cache
   *   Whether to use cache.
   *
   * @return array|null
   *   The API response or NULL on failure.
   */
  protected function retryRequest($method, $endpoint, array $options, $use_cache) {
    $retry_attempts = $this->config->get('api_retry_attempts') ?: 0;
    
    for ($i = 0; $i < $retry_attempts; $i++) {
      sleep(pow(2, $i)); // Exponential backoff
      
      $result = $this->request($method, $endpoint, $options, $use_cache);
      if ($result !== NULL) {
        $this->logger->info('Mautic API request succeeded on retry @attempt', [
          '@attempt' => $i + 1,
        ]);
        return $result;
      }
    }

    return NULL;
  }

  /**
   * Clear API cache.
   *
   * @param string|null $endpoint
   *   Specific endpoint to clear, or NULL to clear all.
   */
  public function clearCache($endpoint = NULL) {
    if ($endpoint) {
      $cache_key = 'mautic_api:' . md5($endpoint);
      $this->cacheBackend->delete($cache_key);
    }
    else {
      $this->cacheBackend->deleteMultiple(['mautic_api']);
    }
  }

}