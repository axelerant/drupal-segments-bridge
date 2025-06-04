<?php

namespace Drupal\mautic_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Handles Mautic form redirects and sets contact ID cookie.
 */
class MauticRedirectHandler extends ControllerBase {

  /**
   * Handle Mautic form redirect - extract contact_id and set cookie.
   *
   * URL: /mautic-redirect?contact_id=123&redirect=/
   */
  public function handleRedirect(Request $request) {
    // Get contact_id from URL
    $contact_id = $request->query->get('contact_id');
    
    // Get redirect destination (default to home page)
    $redirect_to = $request->query->get('redirect', '/');
    
    // Create redirect response
    $response = new RedirectResponse($redirect_to);
    
    // Set cookie if we have a valid contact_id
    if (!empty($contact_id) && is_numeric($contact_id)) {
      $cookie = Cookie::create(
        'mtc_id',                     // Cookie name (same as Mautic uses)
        $contact_id,                  // Contact ID value
        time() + (86400 * 365),       // Expires in 1 year
        '/',                          // Available on all paths
        null,                         // Current domain
        $request->isSecure(),         // Secure if HTTPS
        false                         // Allow JavaScript access
      );
      
      $response->headers->setCookie($cookie);
      
      // Log success
      \Drupal::logger('mautic_integration')->info('Contact ID @id stored in cookie', [
        '@id' => $contact_id
      ]);
    }
    
    return $response;
  }
}