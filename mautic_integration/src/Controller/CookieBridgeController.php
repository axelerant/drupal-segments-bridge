<?php

namespace Drupal\mautic_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Controller for handling cookie bridge data from JavaScript.
 */
class CookieBridgeController extends ControllerBase {

  /**
   * The session service.
   */
  protected $session;

  /**
   * The logger.
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(SessionInterface $session, LoggerInterface $logger) {
    $this->session = $session;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('session'),
      $container->get('logger.factory')->get('mautic_integration')
    );
  }

  /**
   * Store cookie data sent from JavaScript.
   */
  public function storeCookies(Request $request) {
    try {
      $data = json_decode($request->getContent(), true);
      
      if (empty($data['cookies'])) {
        return new JsonResponse([
          'success' => false,
          'message' => 'No cookie data provided'
        ], 400);
      }

      $cookies = $data['cookies'];
      
      // Store in session for MauticSessionTracker to access
      $this->session->set('mautic_cookies', $cookies);
      
      // Log the received cookies
      $this->logger->info('Received Mautic cookies from JavaScript: @cookies', [
        '@cookies' => json_encode(array_keys($cookies))
      ]);
      
      return new JsonResponse([
        'success' => true,
        'message' => 'Cookies stored successfully',
        'cookies_received' => array_keys($cookies)
      ]);
      
    } catch (\Exception $e) {
      $this->logger->error('Error storing cookies: @error', [
        '@error' => $e->getMessage()
      ]);
      
      return new JsonResponse([
        'success' => false,
        'message' => 'Error storing cookies: ' . $e->getMessage()
      ], 500);
    }
  }
}