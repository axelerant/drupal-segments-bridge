<?php

namespace Drupal\mautic_integration\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;

/**
 * Mautic Session Tracker service.
 */
class MauticSessionTracker {

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

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
    RequestStack $request_stack,
    MauticApiClient $api_client,
    LoggerInterface $logger
  ) {
    $this->requestStack = $request_stack;
    $this->apiClient = $api_client;
    $this->logger = $logger;
  }

  /**
   * Get the Mautic session ID from cookie or other sources.
   *
   * @return string|null
   *   The Mautic session ID or contact ID from cookies/storage.
   */
  public function getMauticSessionId() {
    $request = $this->requestStack->getCurrentRequest();
    
    if (!$request) {
      return NULL;
    }

    // Method 1: Check URL parameter (when redirecting from Mautic)
    $contact_id = $request->query->get('contact_id');
    if (!empty($contact_id) && is_numeric($contact_id)) {
      $this->logger->debug('Found contact ID in URL: @id', ['@id' => $contact_id]);
      return $contact_id;
    }

    // Method 2: Check cookie (set by our redirect handler)
    $cookie_value = $request->cookies->get('mtc_id');
    if (!empty($cookie_value) && is_numeric($cookie_value)) {
      $this->logger->debug('Found contact ID in cookie: @id', ['@id' => $cookie_value]);
      return $cookie_value;
    }

    // Method 3: Check session for cookies stored by JavaScript bridge
    $session = $request->getSession();
    if ($session && $session->has('mautic_cookies')) {
      $stored_cookies = $session->get('mautic_cookies');
      foreach ($cookie_names as $cookie_name) {
        if (!empty($stored_cookies[$cookie_name])) {
          $this->logger->debug('Found Mautic tracking ID in session @cookie: @id', [
            '@cookie' => $cookie_name,
            '@id' => $stored_cookies[$cookie_name],
          ]);
          return $stored_cookies[$cookie_name];
        }
      }
    }

    $this->logger->debug('No Mautic tracking ID found in any source.');
    return NULL;
  }

  /**
   * Get contact information based on tracking ID from cookie.
   *
   * @param string|null $tracking_id
   *   The tracking ID from cookie. If NULL, will try to get from cookies.
   *
   * @return array|null
   *   Contact information or NULL if not found.
   */
  public function getContactBySession($tracking_id = NULL) {
    if ($tracking_id === NULL) {
      $tracking_id = $this->getMauticSessionId();
    }

    if (empty($tracking_id)) {
      return NULL;
    }

    try {
      // Method 1: If tracking_id is numeric, try it as direct contact ID
      if (is_numeric($tracking_id)) {
        $contact = $this->apiClient->getContact($tracking_id);
        if (!empty($contact['contact'])) {
          $this->logger->debug('Found contact by direct ID: @id', ['@id' => $tracking_id]);
          return $contact['contact'];
        }
      }

      // Method 2: Search by device ID
      $contacts = $this->apiClient->getContacts([
        'search' => 'device_id:' . $tracking_id,
        'limit' => 1,
      ]);

      if (!empty($contacts['contacts'])) {
        $this->logger->debug('Found contact by device_id search: @id', ['@id' => $tracking_id]);
        return reset($contacts['contacts']);
      }

      // Method 3: Search by mtc_id field
      $contacts = $this->apiClient->getContacts([
        'search' => 'mtc_id:' . $tracking_id,
        'limit' => 1,
      ]);

      if (!empty($contacts['contacts'])) {
        $this->logger->debug('Found contact by mtc_id search: @id', ['@id' => $tracking_id]);
        return reset($contacts['contacts']);
      }

      $this->logger->debug('No contact found for tracking ID: @id', [
        '@id' => $tracking_id,
      ]);
      return NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting contact by tracking ID: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Get contact segments based on session ID.
   *
   * @param string|null $session_id
   *   The session ID. If NULL, will try to get from cookies.
   *
   * @return array
   *   Array of segment names or empty array if none found.
   */
  public function getContactSegments($session_id = NULL) {
    $contact = $this->getContactBySession($session_id);
    
    if (empty($contact) || empty($contact['id'])) {
      return [];
    }

    try {
      // Get contact with full details including segments
      $contact_details = $this->apiClient->getContact($contact['id']);
      
      if (empty($contact_details['contact'])) {
        return [];
      }

      $segments = [];
      $contact_data = $contact_details['contact'];
      
      // Get segments from lists
      if (!empty($contact_data['lists'])) {
        foreach ($contact_data['lists'] as $list) {
          if (!empty($list['name'])) {
            $segments[] = $list['name'];
          }
        }
      }

      // Also get segments from tags
      if (!empty($contact_data['tags'])) {
        foreach ($contact_data['tags'] as $tag) {
          if (!empty($tag['tag'])) {
            $segments[] = $tag['tag'];
          }
        }
      }

      return array_unique($segments);
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting contact segments: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Check if Mautic tracking is active for current session.
   *
   * @return bool
   *   TRUE if tracking is active, FALSE otherwise.
   */
  public function isTrackingActive() {
    return !empty($this->getMauticSessionId());
  }

  /**
   * Get all available tracking information for debugging.
   *
   * @return array
   *   Tracking information array.
   */
  public function getTrackingInfo() {
    $request = $this->requestStack->getCurrentRequest();
    
    if (!$request) {
      return ['error' => 'No request available'];
    }

    $info = [
      'session_id' => $this->getMauticSessionId(),
      'cookies' => [],
      'headers' => [],
      'url_params' => [],
    ];

    // Get URL parameters
    $info['url_params'] = $request->query->all();

    // Get all cookies for debugging
    foreach ($request->cookies->all() as $name => $value) {
      if (strpos($name, 'mtc') !== FALSE || strpos($name, 'mautic') !== FALSE) {
        $info['cookies'][$name] = $value;
      }
    }

    // Get relevant headers
    $relevant_headers = ['user-agent', 'referer', 'accept-language'];
    foreach ($relevant_headers as $header) {
      $value = $request->headers->get($header);
      if ($value) {
        $info['headers'][$header] = $value;
      }
    }

    return $info;
  }

}