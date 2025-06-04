<?php

namespace Drupal\mautic_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mautic_integration\Service\MauticSessionTracker;
use Drupal\mautic_integration\Service\MauticApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for debugging Mautic tracking issues.
 */
class DebugController extends ControllerBase {

  /**
   * The session tracker service.
   *
   * @var \Drupal\mautic_integration\Service\MauticSessionTracker
   */
  protected $sessionTracker;

  /**
   * The API client service.
   *
   * @var \Drupal\mautic_integration\Service\MauticApiClient
   */
  protected $apiClient;

  /**
   * Constructor.
   */
  public function __construct(
    MauticSessionTracker $session_tracker,
    MauticApiClient $api_client
  ) {
    $this->sessionTracker = $session_tracker;
    $this->apiClient = $api_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('mautic_integration.session_tracker'),
      $container->get('mautic_integration.api_client')
    );
  }

  /**
   * Debug tracking information.
   *
   * @return array
   *   Render array for the debug page.
   */
  public function debugTracking() {
    $config = $this->config('mautic_integration.settings');
    $tracking_info = $this->sessionTracker->getTrackingInfo();
    $session_id = $this->sessionTracker->getMauticSessionId();
    
    // Get contact information if session exists
    $contact_info = NULL;
    $segments = [];
    if ($session_id) {
      $contact_info = $this->sessionTracker->getContactBySession($session_id);
      $segments = $this->sessionTracker->getContactSegments($session_id);
    }

    // Check mtc.js accessibility
    $mautic_url = $config->get('mautic_url');
    $mtc_js_status = $this->checkMtcJsAccessibility($mautic_url);

    $build = [
      '#theme' => 'mautic_debug_page',
      '#attached' => [
        'library' => ['core/drupal.ajax'],
      ],
    ];

    // Basic Configuration
    $build['config'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuration Status'),
      '#open' => TRUE,
      'content' => [
        '#markup' => $this->renderConfigStatus($config),
      ],
    ];

    // Tracking Script Status
    $build['script'] = [
      '#type' => 'details',
      '#title' => $this->t('Tracking Script Status'),
      '#open' => TRUE,
      'content' => [
        '#markup' => $this->renderScriptStatus($config, $mtc_js_status),
      ],
    ];

    // Cookie Information
    $build['cookies'] = [
      '#type' => 'details',
      '#title' => $this->t('Cookie Information'),
      '#open' => TRUE,
      'content' => [
        '#markup' => $this->renderCookieStatus($tracking_info),
      ],
    ];

    // Contact Information
    $build['contact'] = [
      '#type' => 'details',
      '#title' => $this->t('Contact Information'),
      '#open' => TRUE,
      'content' => [
        '#markup' => $this->renderContactStatus($session_id, $contact_info, $segments),
      ],
    ];

    // Troubleshooting Tips
    $build['tips'] = [
      '#type' => 'details',
      '#title' => $this->t('Troubleshooting Tips'),
      '#open' => FALSE,
      'content' => [
        '#markup' => $this->renderTroubleshootingTips($mautic_url),
      ],
    ];

    return $build;
  }

  /**
   * Check if mtc.js is accessible.
   *
   * @param string $mautic_url
   *   The Mautic URL.
   *
   * @return array
   *   Status information about mtc.js accessibility.
   */
  protected function checkMtcJsAccessibility($mautic_url) {
    if (empty($mautic_url)) {
      return ['status' => 'error', 'message' => 'Mautic URL not configured'];
    }

    $mtc_url = rtrim($mautic_url, '/') . '/mtc.js';
    
    try {
      $response = \Drupal::httpClient()->get($mtc_url, [
        'timeout' => 10,
        'headers' => [
          'User-Agent' => 'Drupal Mautic Integration Debug',
        ],
      ]);

      $status_code = $response->getStatusCode();
      $content_type = $response->getHeaderLine('Content-Type');
      
      if ($status_code === 200) {
        return [
          'status' => 'success',
          'message' => 'mtc.js is accessible',
          'url' => $mtc_url,
          'content_type' => $content_type,
        ];
      }
      else {
        return [
          'status' => 'error',
          'message' => "mtc.js returned status code: $status_code",
          'url' => $mtc_url,
        ];
      }
    }
    catch (\Exception $e) {
      return [
        'status' => 'error',
        'message' => 'Cannot access mtc.js: ' . $e->getMessage(),
        'url' => $mtc_url,
      ];
    }
  }

  /**
   * Render configuration status.
   */
  protected function renderConfigStatus($config) {
    $items = [];
    
    $mautic_url = $config->get('mautic_url');
    $items[] = $mautic_url ? 
      "✅ Mautic URL: <code>$mautic_url</code>" : 
      "❌ Mautic URL: Not configured";

    $tracking_enabled = $config->get('tracking_enabled');
    $items[] = $tracking_enabled ? 
      "✅ Tracking: Enabled" : 
      "❌ Tracking: Disabled";

    $auth_method = $config->get('oauth2_enabled') ? 'OAuth2' : 
      ($config->get('basic_auth_enabled') ? 'Basic Auth' : 'None');
    $items[] = "🔐 Authentication: $auth_method";

    return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
  }

  /**
   * Render script status.
   */
  protected function renderScriptStatus($config, $mtc_js_status) {
    $items = [];
    
    // Script generation method
    $custom_script = $config->get('tracking_script');
    if (!empty($custom_script)) {
      $items[] = "📝 Script Type: Custom script provided";
    } else {
      $items[] = "🤖 Script Type: Auto-generated script";
    }

    // mtc.js accessibility
    if ($mtc_js_status['status'] === 'success') {
      $items[] = "✅ mtc.js Status: Accessible at <code>{$mtc_js_status['url']}</code>";
    } else {
      $items[] = "❌ mtc.js Status: {$mtc_js_status['message']}";
      if (isset($mtc_js_status['url'])) {
        $items[] = "🔗 Trying to access: <code>{$mtc_js_status['url']}</code>";
      }
    }

    return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
  }

  /**
   * Render cookie status.
   */
  protected function renderCookieStatus($tracking_info) {
    $items = [];
    
    if (!empty($tracking_info['session_id'])) {
      $items[] = "✅ Session ID Found: <code>{$tracking_info['session_id']}</code>";
    } else {
      $items[] = "❌ Session ID: Not found";
    }

    if (!empty($tracking_info['cookies'])) {
      foreach ($tracking_info['cookies'] as $name => $value) {
        $items[] = "🍪 Cookie <code>$name</code>: <code>$value</code>";
      }
    } else {
      $items[] = "❌ Mautic Cookies: None found";
    }

    return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
  }

  /**
   * Render contact status.
   */
  protected function renderContactStatus($session_id, $contact_info, $segments) {
    $items = [];
    
    if ($session_id && $contact_info) {
      $items[] = "✅ Contact Found: ID {$contact_info['id']}";
      if (!empty($contact_info['fields']['core']['email']['value'])) {
        $items[] = "📧 Email: {$contact_info['fields']['core']['email']['value']}";
      }
      if (!empty($segments)) {
        $items[] = "🎯 Segments: " . implode(', ', $segments);
      } else {
        $items[] = "🎯 Segments: None";
      }
    } elseif ($session_id) {
      $items[] = "⚠️ Session ID found but no contact information available";
    } else {
      $items[] = "❌ No tracking session detected";
    }

    return '<ul><li>' . implode('</li><li>', $items) . '</li></ul>';
  }

  /**
   * Render troubleshooting tips.
   */
  protected function renderTroubleshootingTips($mautic_url) {
    $current_domain = \Drupal::request()->getHost();
    $current_scheme = \Drupal::request()->getScheme();
    $current_url = "$current_scheme://$current_domain";

    $tips = [
      "<strong>CORS Configuration:</strong> In your Mautic, go to Settings > Configuration > CORS Settings and add: <code>$current_url</code>",
      "<strong>Cookie Domain:</strong> Check your Mautic Configuration > System Settings > Cookie settings",
      "<strong>HTTPS/HTTP Match:</strong> Make sure your Mautic URL scheme matches your site's scheme",
      "<strong>Browser Console:</strong> Check for JavaScript errors in browser developer tools",
      "<strong>Network Tab:</strong> Look for requests to <code>" . rtrim($mautic_url, '/') . "/mtc.js</code> and <code>/mtc/event</code>",
      "<strong>Test Different Browser:</strong> Try in incognito/private mode or different browser",
      "<strong>Local Development:</strong> If testing locally, make sure your local domain is added to CORS settings",
    ];

    return '<ol><li>' . implode('</li><li>', $tips) . '</li></ol>';
  }

  /**
   * AJAX endpoint for refreshing debug info.
   */
  public function refreshDebugInfo() {
    $tracking_info = $this->sessionTracker->getTrackingInfo();
    return new JsonResponse($tracking_info);
  }

}