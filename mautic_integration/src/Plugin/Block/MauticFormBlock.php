<?php

namespace Drupal\mautic_integration\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\mautic_integration\Service\MauticFormManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Mautic Form' Block.
 *
 * @Block(
 *   id = "mautic_form_block",
 *   admin_label = @Translation("Mautic Form"),
 *   category = @Translation("Mautic Integration"),
 * )
 */
class MauticFormBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The Mautic form manager.
   *
   * @var \Drupal\mautic_integration\Service\MauticFormManager
   */
  protected $formManager;

  /**
   * Constructor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MauticFormManager $form_manager
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->formManager = $form_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('mautic_integration.form_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'form_id' => '',
      'show_title' => TRUE,
      'show_description' => FALSE,
      'custom_css_class' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    
    $config = $this->getConfiguration();

    // Get available forms
    $form_options = $this->formManager->getFormOptions();
    
    if (empty($form_options)) {
      $form['no_forms'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('No forms found. Please check your Mautic API connection and ensure you have published forms.') . 
          '</div>',
      ];
      return $form;
    }

    $form['form_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Form Settings'),
      '#open' => TRUE,
    ];

    $form['form_settings']['form_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Select Mautic Form'),
      '#description' => $this->t('Choose which Mautic form to display in this block.'),
      '#options' => ['' => $this->t('- Select a form -')] + $form_options,
      '#default_value' => $config['form_id'],
      '#required' => TRUE,
    ];

    // Form preview/info - Remove AJAX to avoid form state issues
    $selected_form_id = $config['form_id'];
    if (!empty($selected_form_id)) {
      $form_details = $this->formManager->getFormDetails($selected_form_id);
      if ($form_details) {
        $stats = $this->formManager->getFormStats($selected_form_id);
        
        $form['form_info'] = [
          '#type' => 'details',
          '#title' => $this->t('Current Form Information'),
          '#open' => FALSE,
        ];
        
        $form['form_info']['info'] = [
          '#markup' => '<div class="form-info">' .
            '<p><strong>' . $this->t('Name') . ':</strong> ' . $form_details['name'] . '</p>' .
            '<p><strong>' . $this->t('Description') . ':</strong> ' . ($form_details['description'] ?: $this->t('No description')) . '</p>' .
            '<p><strong>' . $this->t('Submissions') . ':</strong> ' . $stats['submissions'] . '</p>' .
            '<p><strong>' . $this->t('Status') . ':</strong> ' . ($stats['published'] ? $this->t('Published') : $this->t('Unpublished')) . '</p>' .
            '</div>',
        ];
      }
    }

    $form['display_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Display Settings'),
      '#open' => FALSE,
    ];

    $form['display_settings']['show_title'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Form Title'),
      '#description' => $this->t('Display the form title above the form.'),
      '#default_value' => $config['show_title'],
    ];

    $form['display_settings']['show_description'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Form Description'),
      '#description' => $this->t('Display the form description above the form.'),
      '#default_value' => $config['show_description'],
    ];

    $form['display_settings']['custom_css_class'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom CSS Class'),
      '#description' => $this->t('Add custom CSS classes to the form container.'),
      '#default_value' => $config['custom_css_class'],
    ];

    return $form;
  }

  /**
   * AJAX callback for form preview - REMOVED to fix form state issues.
   */
  // public function formPreviewCallback(array &$form, FormStateInterface $form_state) {
  //   return $form['form_preview'];
  // }

  /**
   * {@inheritdoc}
   */
  public function blockValidate($form, FormStateInterface $form_state) {
    $form_id = $form_state->getValue(['form_settings', 'form_id']);
    
    if (empty($form_id)) {
      $form_state->setErrorByName('form_settings][form_id', $this->t('Please select a Mautic form.'));
      return;
    }

    // Basic validation - we'll check form existence when rendering
    if (!is_numeric($form_id)) {
      $form_state->setErrorByName('form_settings][form_id', $this->t('Invalid form ID selected.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    
    $this->configuration['form_id'] = $form_state->getValue(['form_settings', 'form_id']);
    $this->configuration['show_title'] = $form_state->getValue(['display_settings', 'show_title']);
    $this->configuration['show_description'] = $form_state->getValue(['display_settings', 'show_description']);
    $this->configuration['custom_css_class'] = $form_state->getValue(['display_settings', 'custom_css_class']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();
    $form_id = $config['form_id'];

    if (empty($form_id)) {
      return [
        '#markup' => '<div class="messages messages--warning">' . 
          $this->t('No form selected. Please configure this block.') . 
          '</div>',
      ];
    }

    // Get form details
    $form_details = $this->formManager->getFormDetails($form_id);
    if (!$form_details) {
      return [
        '#markup' => '<div class="messages messages--error">' . 
          $this->t('Form not found. Please check your configuration.') . 
          '</div>',
      ];
    }

    // Render the form
    $build = $this->formManager->renderForm($form_id);
    
    // Add configuration settings to the build
    $build['#show_title'] = $config['show_title'];
    $build['#show_description'] = $config['show_description'];
    $build['#custom_css_class'] = $config['custom_css_class'];

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return ['url.path', 'url.query_args'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $config = $this->getConfiguration();
    $form_id = $config['form_id'];
    
    $tags = parent::getCacheTags();
    if (!empty($form_id)) {
      $tags[] = 'mautic_form:' . $form_id;
    }
    
    return $tags;
  }

}