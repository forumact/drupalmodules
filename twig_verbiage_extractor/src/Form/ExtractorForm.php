<?php

namespace Drupal\twig_verbiage_extractor\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration and trigger form for Twig Verbiage Extractor.
 */
class ExtractorForm extends FormBase {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructor.
   */
  public function __construct(StateInterface $state) {
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'twig_verbiage_extractor_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Show download link if CSV exists from previous run.
    $csv_path = $this->state->get('tve_csv_path', '');
    $stats    = $this->state->get('tve_stats', []);

    if ($csv_path && file_exists(\Drupal::service('file_system')->realpath($csv_path))) {
      $form['previous_run'] = [
        '#type'   => 'fieldset',
        '#title'  => $this->t('Previous Export'),
      ];
      if (!empty($stats)) {
        $form['previous_run']['summary'] = [
          '#markup' => $this->t(
            '<p><strong>Last run:</strong> @files files processed, @vars variables found, @matched auto-filled from API.</p>',
            [
              '@files'   => $stats['files'] ?? 0,
              '@vars'    => $stats['variables'] ?? 0,
              '@matched' => $stats['matched'] ?? 0,
            ]
          ),
        ];
      }
      $form['previous_run']['download'] = [
        '#type'  => 'link',
        '#title' => $this->t('Download CSV'),
        '#url'   => \Drupal\Core\Url::fromRoute('twig_verbiage_extractor.download'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];
    }

    $form['settings'] = [
      '#type'  => 'fieldset',
      '#title' => $this->t('Settings'),
    ];

    $form['settings']['templates_path'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Templates Directory Path'),
      '#description'   => $this->t('Absolute path to the Twig templates folder.'),
      '#default_value' => $this->state->get(
        'tve_templates_path',
        '/var/www/memberportal-source/web/themes/custom/hzn_horizonblue/templates'
      ),
      '#required'      => TRUE,
      '#maxlength'     => 512,
    ];

    $form['settings']['api_base_url'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('API Base URL'),
      '#description'   => $this->t('Base URL of the digital_sites API. E.g. https://localhost:8444'),
      '#default_value' => $this->state->get('tve_api_base_url', 'https://localhost:8444'),
      '#required'      => TRUE,
      '#maxlength'     => 512,
    ];

    $form['settings']['organization'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Organization'),
      '#description'   => $this->t('Organization filter for the API. E.g. commercial'),
      '#default_value' => $this->state->get('tve_organization', 'commercial'),
      '#required'      => TRUE,
      '#maxlength'     => 64,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Start Export'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $path = trim($form_state->getValue('templates_path'));
    if (!is_dir($path)) {
      $form_state->setErrorByName(
        'templates_path',
        $this->t('The templates directory does not exist: @path', ['@path' => $path])
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $templates_path = trim($form_state->getValue('templates_path'));
    $api_base_url   = rtrim(trim($form_state->getValue('api_base_url')), '/');
    $organization   = trim($form_state->getValue('organization'));

    // Persist settings.
    $this->state->set('tve_templates_path', $templates_path);
    $this->state->set('tve_api_base_url', $api_base_url);
    $this->state->set('tve_organization', $organization);

    // Clear previous state.
    $this->state->delete('tve_api_data');
    $this->state->delete('tve_file_list');
    $this->state->delete('tve_csv_path');
    $this->state->delete('tve_stats');

    // Build batch.
    $batch = [
      'title'            => $this->t('Extracting Twig Verbiage...'),
      'operations'       => [],
      'finished'         => '\Drupal\twig_verbiage_extractor\Batch\TwigExtractorBatch::finished',
      'init_message'     => $this->t('Initialising extraction...'),
      'progress_message' => $this->t('Processing @current of @total operations.'),
      'error_message'    => $this->t('An error occurred during extraction.'),
    ];

    // Op 1: Fetch API data.
    $batch['operations'][] = [
      '\Drupal\twig_verbiage_extractor\Batch\TwigExtractorBatch::fetchApiData',
      [$api_base_url, $organization],
    ];

    // Op 2: Discover files + initialise CSV.
    $batch['operations'][] = [
      '\Drupal\twig_verbiage_extractor\Batch\TwigExtractorBatch::discoverFiles',
      [$templates_path],
    ];

    // Op 3: Process files (one batch op per file, added dynamically in discoverFiles).
    // Files are processed via a single operation that loops through state file list
    // in chunks — but we add one operation per file for true granularity.
    // We defer file ops to after discovery by using a dispatcher operation.
    $batch['operations'][] = [
      '\Drupal\twig_verbiage_extractor\Batch\TwigExtractorBatch::dispatchFileOps',
      [],
    ];

    batch_set($batch);
  }

}
