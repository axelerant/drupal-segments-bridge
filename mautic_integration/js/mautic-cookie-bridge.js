/**
 * @file
 * Mautic Cookie Bridge - Detects cross-domain Mautic cookies and sends them to Drupal.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Mautic Cookie Bridge behavior.
   */
  Drupal.behaviors.mauticCookieBridge = {
    attach: function (context, settings) {
      // Only run once per page load
      if (context !== document) {
        return;
      }

      console.log('🔗 Mautic Cookie Bridge: Starting...');
      
      var self = this;
      
      // Try to detect and send cookies immediately
      if (!this.initCookieBridge()) {
        // Retry after 2 seconds if Mautic hasn't set cookies yet
        setTimeout(function() {
          if (!self.initCookieBridge()) {
            // Final retry after 5 seconds
            setTimeout(function() {
              self.initCookieBridge();
            }, 3000);
          }
        }, 2000);
      }
    },

    /**
     * Get Mautic cookies from browser.
     */
    getMauticCookies: function() {
      var cookies = {};
      var allCookies = document.cookie.split(';');
      
      allCookies.forEach(function(cookie) {
        var parts = cookie.trim().split('=');
        if (parts.length === 2) {
          var name = parts[0];
          var value = decodeURIComponent(parts[1]);
          
          // Look for Mautic-related cookies
          if (name.indexOf('mtc') === 0 || name.indexOf('mautic') === 0) {
            cookies[name] = value;
            console.log('🍪 Found Mautic cookie:', name, '=', value);
          }
        }
      });
      
      return cookies;
    },

    /**
     * Send cookies to Drupal backend.
     */
    sendCookiesToDrupal: function(cookies) {
      if (Object.keys(cookies).length === 0) {
        console.log('⚠️ No Mautic cookies found to send');
        return Promise.resolve(false);
      }
      
      console.log('📤 Sending cookies to Drupal:', cookies);
      
      return fetch(drupalSettings.path.baseUrl + 'mautic-integration/store-cookies', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
          cookies: cookies
        })
      })
      .then(function(response) {
        return response.json();
      })
      .then(function(data) {
        if (data.success) {
          console.log('✅ Cookies sent to Drupal successfully:', data);
          return true;
        } else {
          console.error('❌ Failed to send cookies:', data.message);
          return false;
        }
      })
      .catch(function(error) {
        console.error('❌ Error sending cookies to Drupal:', error);
        return false;
      });
    },

    /**
     * Check if page needs refresh to show segments.
     */
    shouldRefreshPage: function() {
      var hasSegmentsBlock = document.querySelector('.mautic-segments-block');
      if (!hasSegmentsBlock) {
        return false;
      }
      
      // Check if segments block exists but has no segments displayed
      var hasSegments = hasSegmentsBlock.querySelector('.mautic-segment-item, .mautic-segment-badge');
      var hasEmptyMessage = hasSegmentsBlock.querySelector('.mautic-segments-empty');
      
      // Refresh if block exists but shows no segments and no empty message
      return !hasSegments && !hasEmptyMessage;
    },

    /**
     * Main initialization function.
     */
    initCookieBridge: function() {
      var cookies = this.getMauticCookies();
      var self = this;
      
      if (Object.keys(cookies).length === 0) {
        console.log('⏳ No Mautic cookies found yet, will retry...');
        return false;
      }
      
      this.sendCookiesToDrupal(cookies).then(function(success) {
        if (success && self.shouldRefreshPage()) {
          console.log('🔄 Refreshing page to load segments...');
          setTimeout(function() {
            window.location.reload();
          }, 500);
        }
      });
      
      return true;
    }
  };

  // Expose functions globally for debugging
  Drupal.mauticCookieBridge = {
    getCookies: function() {
      return Drupal.behaviors.mauticCookieBridge.getMauticCookies();
    },
    sendCookies: function(cookies) {
      return Drupal.behaviors.mauticCookieBridge.sendCookiesToDrupal(cookies);
    },
    init: function() {
      return Drupal.behaviors.mauticCookieBridge.initCookieBridge();
    }
  };

  console.log('🔗 Mautic Cookie Bridge loaded. Test with: Drupal.mauticCookieBridge.getCookies()');

})(Drupal, drupalSettings);