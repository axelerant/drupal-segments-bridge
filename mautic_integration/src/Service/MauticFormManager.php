<?php

namespace Drupal\mautic_integration\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Mautic Form Manager service.
 */
class MauticFormManager {

  /**
   * The Mautic API client.
   *
   * @var \Drupal\mautic_integration\Service\MauticApiClient
   */
  protected $apiClient;

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
    CacheBackendInterface $cache_backend,
    LoggerInterface $logger
  ) {
    $this->apiClient = $api_client;
    $this->cacheBackend = $cache_backend;
    $this->logger = $logger;
  }

  /**
   * Get all available forms for selection.
   *
   * @return array
   *   Array of forms with id => name pairs.
   */
  public function getFormOptions() {
    $cache_key = 'mautic_integration:form_options';
    $cached = $this->cacheBackend->get($cache_key);
    
    if ($cached && $cached->valid) {
      return $cached->data;
    }

    try {
      $forms_data = $this->apiClient->getForms(['limit' => 100]);
      $options = [];
      
      if (!empty($forms_data['forms'])) {
        foreach ($forms_data['forms'] as $form) {
          $options[$form['id']] = $form['name'] . ' (ID: ' . $form['id'] . ')';
        }
      }

      // Cache for 1 hour
      $this->cacheBackend->set($cache_key, $options, time() + 3600);
      
      return $options;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch form options: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get form details by ID.
   *
   * @param int $form_id
   *   The form ID.
   *
   * @return array|null
   *   Form details or NULL if not found.
   */
  public function getFormDetails($form_id) {
    if (empty($form_id)) {
      return NULL;
    }

    $cache_key = 'mautic_integration:form_details:' . $form_id;
    $cached = $this->cacheBackend->get($cache_key);
    
    if ($cached && $cached->valid) {
      return $cached->data;
    }

    try {
      $form_data = $this->apiClient->getForm($form_id);
      
      if (!empty($form_data['form'])) {
        $form_details = $form_data['form'];
        
        // Cache for 1 hour
        $this->cacheBackend->set($cache_key, $form_details, time() + 3600);
        
        return $form_details;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch form details for ID @id: @error', [
        '@id' => $form_id,
        '@error' => $e->getMessage(),
      ]);
    }

    return NULL;
  }

  /**
   * Generate form HTML for embedding.
   *
   * @param int $form_id
   *   The form ID.
   * @param array $options
   *   Additional options for form rendering.
   *
   * @return array
   *   Renderable array for the form.
   */
  public function renderForm($form_id, array $options = []) {
    $form_details = $this->getFormDetails($form_id);
    
    if (!$form_details) {
      return [
        '#markup' => '<div class="mautic-form-error">Form not found or unavailable.</div>',
      ];
    }

    // Get Mautic base URL for JavaScript inclusion
    $mautic_url = \Drupal::config('mautic_integration.settings')->get('mautic_url');
    $form_script_url = rtrim($mautic_url, '/') . '/form/' . $form_id;

    return [
      '#theme' => 'mautic_form_block',
      '#form_id' => $form_id,
      '#form_name' => $form_details['name'],
      '#form_description' => $form_details['description'] ?? '',
      '#mautic_url' => $mautic_url,
      '#form_script_url' => $form_script_url,
      '#cache' => [
        'max-age' => 3600, // Cache for 1 hour
        'contexts' => ['url'],
        'tags' => ['mautic_form:' . $form_id],
      ],
      '#attached' => [
        'html_head' => [
          [
            [
              '#type' => 'html_tag',
              '#tag' => 'script',
              '#attributes' => [
                'src' => $form_script_url,
                'type' => 'text/javascript',
                'charset' => 'utf-8',
                'async' => 'async',
              ],
            ],
            'mautic_form_' . $form_id,
          ],
        ],
      ],
    ];
  }

  /**
   * Clear form cache.
   *
   * @param int|null $form_id
   *   Specific form ID to clear, or NULL to clear all.
   */
  public function clearCache($form_id = NULL) {
    if ($form_id) {
      $this->cacheBackend->delete('mautic_integration:form_details:' . $form_id);
      $this->cacheBackend->invalidateTags(['mautic_form:' . $form_id]);
    }
    else {
      $this->cacheBackend->delete('mautic_integration:form_options');
      $this->cacheBackend->invalidateTags(['mautic_forms']);
    }
  }

  /**
   * Get form statistics.
   *
   * @param int $form_id
   *   The form ID.
   *
   * @return array
   *   Form statistics.
   */
  public function getFormStats($form_id) {
    $form_details = $this->getFormDetails($form_id);
    
    if (!$form_details) {
      return [];
    }

    return [
      'submissions' => $form_details['submissionCount'] ?? 0,
      'published' => $form_details['isPublished'] ?? FALSE,
      'created' => $form_details['dateAdded'] ?? NULL,
      'modified' => $form_details['dateModified'] ?? NULL,
    ];
  }

}