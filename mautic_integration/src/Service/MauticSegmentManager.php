<?php

namespace Drupal\mautic_integration\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Mautic Segment Manager service.
 */
class MauticSegmentManager {

  /**
   * The Mautic API client.
   *
   * @var \Drupal\mautic_integration\Service\MauticApiClient
   */
  protected $apiClient;

  /**
   * The session tracker.
   *
   * @var \Drupal\mautic_integration\Service\MauticSessionTracker
   */
  protected $sessionTracker;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

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
    MauticSessionTracker $session_tracker,
    CacheBackendInterface $cache_backend,
    LoggerInterface $logger
  ) {
    $this->apiClient = $api_client;
    $this->sessionTracker = $session_tracker;
    $this->cacheBackend = $cache_backend;
    $this->logger = $logger;
  }

  /**
   * Get segments for the current user based on mtc_id cookie.
   *
   * @return array
   *   Array of segment names.
   */
  public function getCurrentUserSegments() {
    $tracking_id = $this->sessionTracker->getMauticSessionId();
    
    if (!$tracking_id) {
      $this->logger->debug('No Mautic tracking ID found in cookies.');
      return [];
    }

    return $this->getUserSegmentsByTrackingId($tracking_id);
  }

  /**
   * Get segments for a user by tracking ID.
   *
   * @param string $tracking_id
   *   The Mautic tracking ID from cookie.
   *
   * @return array
   *   Array of segment names.
   */
  public function getUserSegmentsByTrackingId($tracking_id) {
    $cache_key = 'mautic_integration:user_segments:' . md5($tracking_id);
    $cached = $this->cacheBackend->get($cache_key);
    
    if ($cached && $cached->valid) {
      return $cached->data;
    }

    try {
      // Find the contact using the improved tracking ID logic
      $contact = $this->findContactByTrackingId($tracking_id);
      
      if (!$contact) {
        $this->logger->debug('No contact found for tracking ID: @id', ['@id' => $tracking_id]);
        return [];
      }

      // Get contact details with segments
      $contact_segment_blocks = $this->apiClient->getContactSegments($contact['id']);
      
      if (!$contact_segment_blocks || empty($contact_segment_blocks['lists'])) {
        $this->logger->debug('Segment details not found for Contact ID: @id', ['@id' => $contact['id']]);
        return [];
      }

      $segments = $this->extractSegmentNames($contact_segment_blocks);
      
      // Cache for 5 minutes (configurable)
      $cache_lifetime = \Drupal::config('mautic_integration.settings')->get('segment_cache_lifetime') ?: 300;
      $this->cacheBackend->set($cache_key, $segments, time() + $cache_lifetime);
      
      return $segments;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get user segments for tracking ID @id: @error', [
        '@id' => $tracking_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Find contact by tracking ID using multiple methods.
   *
   * @param string $tracking_id
   *   The tracking ID from cookie.
   *
   * @return array|null
   *   Contact data or NULL if not found.
   */
  protected function findContactByTrackingId($tracking_id) {
    // Method 1: If tracking_id is numeric, try it as direct contact ID (most common for mtc_id)
    if (is_numeric($tracking_id)) {
      try {
        $contact = $this->apiClient->getContact($tracking_id);
        if (!empty($contact['contact'])) {
          $this->logger->debug('Found contact by direct ID lookup: @id', ['@id' => $tracking_id]);
          return $contact['contact'];
        }
      }
      catch (\Exception $e) {
        // Contact ID doesn't exist, continue to other methods
        $this->logger->debug('Direct contact ID lookup failed for @id', ['@id' => $tracking_id]);
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

    $this->logger->debug('No contact found using any method for tracking ID: @id', ['@id' => $tracking_id]);
    return NULL;
  }

  /**
   * Extract segment names from contact data.
   *
   * @param array $contact
   *   The contact data.
   *
   * @return array
   *   Array of segment names.
   */
  protected function extractSegmentNames(array $contact) {
    $segments = [];
    // Check for segments in lists field
    if (!empty($contact['lists']) && is_array($contact['lists'])) {
      foreach ($contact['lists'] as $list) {
        if (!empty($list['name'])) {
          $segments[] = $list['name'];
        }
      }
    }

    // Also check in tags (which might be used as segments)
    if (!empty($contact['tags']) && is_array($contact['tags'])) {
      foreach ($contact['tags'] as $tag) {
        if (!empty($tag['tag'])) {
          $segments[] = $tag['tag'];
        }
      }
    }
    // Remove duplicates and return
    return array_unique($segments);
  }

  /**
   * Get all available segments for selection.
   *
   * @return array
   *   Array of segments with id => name pairs.
   */
  public function getSegmentOptions() {
    $cache_key = 'mautic_integration:segment_options';
    $cached = $this->cacheBackend->get($cache_key);
    
    if ($cached && $cached->valid) {
      return $cached->data;
    }

    try {
      $segments_data = $this->apiClient->getSegments(['limit' => 100]);
      $options = [];
      
      if (!empty($segments_data['lists'])) {
        foreach ($segments_data['lists'] as $segment) {
          $options[$segment['id']] = $segment['name'] . ' (ID: ' . $segment['id'] . ')';
        }
      }

      // Cache for 1 hour
      $this->cacheBackend->set($cache_key, $options, time() + 3600);
      
      return $options;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch segment options: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get detailed information about current user.
   *
   * @return array
   *   User information including contact details and segments.
   */
  public function getCurrentUserInfo() {
    $session_id = $this->sessionTracker->getMauticSessionId();
    
    if (!$session_id) {
      return [
        'session_id' => NULL,
        'contact' => NULL,
        'segments' => [],
        'tracking_active' => FALSE,
      ];
    }

    $contact = $this->findContactByTrackingId($session_id);
    $segments = $this->getUserSegmentsByTrackingId($session_id);

    return [
      'session_id' => $session_id,
      'contact' => $contact,
      'segments' => $segments,
      'tracking_active' => TRUE,
    ];
  }

  /**
   * Clear segment cache.
   *
   * @param string|null $session_id
   *   Specific session ID to clear, or NULL to clear all.
   */
  public function clearCache($session_id = NULL) {
    if ($session_id) {
      $cache_key = 'mautic_integration:user_segments:' . md5($session_id);
      $this->cacheBackend->delete($cache_key);
    }
    else {
      $this->cacheBackend->delete('mautic_integration:segment_options');
      // Clear all user segment caches
      $this->cacheBackend->deleteMultiple(['mautic_integration:user_segments']);
    }
  }

}