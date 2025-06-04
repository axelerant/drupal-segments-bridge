<?php

namespace Drupal\mautic_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\mautic_integration\Service\ConfigValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Controller for testing Mautic API connection.
 */
class ApiTestController extends ControllerBase {

  /**
   * The config validator service.
   *
   * @var \Drupal\mautic_integration\Service\ConfigValidator
   */
  protected $configValidator;

  /**
   * Constructor.
   */
  public function __construct(ConfigValidator $config_validator) {
    $this->configValidator = $config_validator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('mautic_integration.config_validator')
    );
  }

  /**
   * Test the Mautic API connection.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with test results.
   */
  public function testConnection() {
    $results = $this->configValidator->validateConfiguration();
    
    $response_data = [
      'success' => $results['success'],
      'message' => $results['success'] ? 
        $this->t('Configuration validation successful!') : 
        $this->t('Configuration validation failed.'),
      'details' => [
        'errors' => $results['errors'],
        'warnings' => $results['warnings'],
        'info' => $results['info'],
      ],
    ];

    return new JsonResponse($response_data);
  }

}