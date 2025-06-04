<?php

namespace Drupal\mautic_integration\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\mautic_integration\Service\MauticSegmentManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Mautic User Segments' Block.
 *
 * @Block(
 *   id = "mautic_segments_block",
 *   admin_label = @Translation("Mautic User Segments"),
 *   category = @Translation("Mautic Integration"),
 * )
 */
class MauticSegmentsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The Mautic segment manager.
   *
   * @var \Drupal\mautic_integration\Service\MauticSegmentManager
   */
  protected $segmentManager;

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MauticSegmentManager $segment_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->segmentManager = $segment_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('mautic_integration.segment_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'display_mode' => 'list',
      'show_title' => TRUE,
      'title_text' => 'Your Segments',
      'show_when_empty' => FALSE,
      'empty_message' => 'No segments found.',
      'show_contact_info' => FALSE,
      'custom_css_class' => '',
      'separator' => ', ',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    
    $config = $this->getConfiguration();

    $form['display_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Display Settings'),
      '#open' => TRUE,
    ];

    $form['display_settings']['display_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Display Mode'),
      '#description' => $this->t('How to display the segments.'),
      '#options' => [
        'list' => $this->t('Bulleted List'),
        'inline' => $this->t('Inline (comma separated)'),
        'badges' => $this->t('Badges/Tags'),
        'custom' => $this->t('Custom (specify separator)'),
      ],
      '#default_value' => $config['display_mode'],
    ];

    $form['display_settings']['separator'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Separator'),
      '#description' => $this->t('Separator for inline and custom display modes.'),
      '#default_value' => $config['separator'],
      '#states' => [
        'visible' => [
          ':input[name="settings[display_settings][display_mode]"]' => [
            ['value' => 'inline'],
            ['value' => 'custom'],
          ],
        ],
      ],
    ];

    $form['display_settings']['show_title'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Title'),
      '#description' => $this->t('Display a title above the segments.'),
      '#default_value' => $config['show_title'],
    ];

    $form['display_settings']['title_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title Text'),
      '#description' => $this->t('Custom title text to display.'),
      '#default_value' => $config['title_text'],
      '#states' => [
        'visible' => [
          ':input[name="settings[display_settings][show_title]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['empty_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Empty State Settings'),
      '#open' => FALSE,
    ];

    $form['empty_settings']['show_when_empty'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Block When No Segments'),
      '#description' => $this->t('Display the block even when the user has no segments.'),
      '#default_value' => $config['show_when_empty'],
    ];

    $form['empty_settings']['empty_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Empty Message'),
      '#description' => $this->t('Message to show when no segments are found.'),
      '#default_value' => $config['empty_message'],
      '#states' => [
        'visible' => [
          ':input[name="settings[empty_settings][show_when_empty]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['debug_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Debug Settings'),
      '#open' => FALSE,
    ];

    $form['debug_settings']['show_contact_info'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Contact Information'),
      '#description' => $this->t('Display additional contact information for debugging (not recommended for production).'),
      '#default_value' => $config['show_contact_info'],
    ];

    $form['styling'] = [
      '#type' => 'details',
      '#title' => $this->t('Styling'),
      '#open' => FALSE,
    ];

    $form['styling']['custom_css_class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom CSS Class'),
      '#description' => $this->t('Add custom CSS classes to the block container.'),
      '#default_value' => $config['custom_css_class'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    
    $this->configuration['display_mode'] = $form_state->getValue(['display_settings', 'display_mode']);
    $this->configuration['separator'] = $form_state->getValue(['display_settings', 'separator']);
    $this->configuration['show_title'] = $form_state->getValue(['display_settings', 'show_title']);
    $this->configuration['title_text'] = $form_state->getValue(['display_settings', 'title_text']);
    $this->configuration['show_when_empty'] = $form_state->getValue(['empty_settings', 'show_when_empty']);
    $this->configuration['empty_message'] = $form_state->getValue(['empty_settings', 'empty_message']);
    $this->configuration['show_contact_info'] = $form_state->getValue(['debug_settings', 'show_contact_info']);
    $this->configuration['custom_css_class'] = $form_state->getValue(['styling', 'custom_css_class']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();
    
    // Get current user's segment information
    $user_info = $this->segmentManager->getCurrentUserInfo();
    $segments = $user_info['segments'];

    // If no segments and not showing when empty, return empty
    if (empty($segments) && !$config['show_when_empty']) {
      return [];
    }

    $build = [
      '#theme' => 'mautic_segments_block',
      '#segments' => $segments,
      '#user_info' => $user_info,
      '#display_mode' => $config['display_mode'],
      '#separator' => $config['separator'],
      '#show_title' => $config['show_title'],
      '#title_text' => $config['title_text'],
      '#show_when_empty' => $config['show_when_empty'],
      '#empty_message' => $config['empty_message'],
      '#show_contact_info' => $config['show_contact_info'],
      '#custom_css_class' => $config['custom_css_class'],
      // '#cache' => [
      //   'max-age' => 300, // Cache for 5 minutes
      //   'contexts' => ['cookies:mtc_id', 'url'],
      //   'tags' => ['mautic_segments'],
      // ],
      '#cache' => [
        'max-age' => -1, // No cache
      ],
    ];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['cookies:mtc_id', 'url.path'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return ['mautic_segments', 'mautic_user_segments'];
  }

}