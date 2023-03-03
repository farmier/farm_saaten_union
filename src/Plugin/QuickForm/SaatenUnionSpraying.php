<?php

namespace Drupal\farm_saaten_union\Plugin\QuickForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickLogTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Saaten Union Spraying Quick Form.
 *
 * @QuickForm(
 *   id = "saaten_union_spraying",
 *   label = @Translation("Spraying"),
 *   description = @Translation("Create a Saaten Union Spraying log."),
 *   helpText = @Translation("Use this form to record spraying records."),
 *   permissions = {
 *     "create input log",
 *   }
 * )
 */
class SaatenUnionSpraying extends QuickFormBase {

  use QuickLogTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;
  
  /**
   * Constructs a QuickFormBase object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MessengerInterface $messenger, EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $messenger);
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('logger'),
    );
  }

/**
 * Retrieves a sorted list of active, non-admin user labels.
 *
 * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
 *   The entity type manager.
 *
 * @return array
 *   An array of user labels indexed by user ID and sorted alphabetically.
 */
  protected function getUserOptions(EntityTypeManagerInterface $entityTypeManager): array {
    // Query active, non-admin users.
    $userQuery = $entityTypeManager->getStorage('user')->getQuery();
    $userQuery->accessCheck(TRUE);
    $userQuery->condition('status', 1);
    $userQuery->condition('uid', '1', '>');
  
    // Load users.
    $userIds = $userQuery->execute();
    $users = $entityTypeManager->getStorage('user')->loadMultiple($userIds);
  
    // Build user options.
    $options = [];
    foreach ($users as $user) {
      $options[$user->id()] = $user->label();
    }
    asort($options);
  
    return $options;
  }
  
/**
  * Helper function to get the labels of active equipment assets, sorted alphabetically.
  *
  * @return string[]
  *   An array of equipment labels indexed by asset id and sorted alphabetically.
  */
protected function getActiveEquipmentLabels(): array {
  try {
    // Query active equipment assets.
    $asset_storage = $this->entityTypeManager->getStorage('asset');
    $asset_ids = $asset_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('status', 'active')
      ->condition('type', 'equipment')
      ->execute();

    // Load equipment assets.
    $equipments = $asset_storage->loadMultiple($asset_ids);

    // Build equipment options.
    $equipment_labels = array_map(function ($equipment) {
      return $equipment->label();
    }, $equipments);
    natcasesort($equipment_labels);

    return $equipment_labels;
  } catch (\Exception $e) {
    // Log the error and return an empty array.
    $this->logger->error('Failed to load equipment assets: @message', [
      '@message' => $e->getMessage(),
    ]);
    return [];
  }
}

  /**
   * Helper function to get the labels of active material assets, sorted alphabetically.
   *
   * @return string[]
   *   An array of material labels indexed by asset id and sorted alphabetically.
   */
  protected function getMaterials(): array {
    try {
      // Get the entity storage for the 'asset' entity type.
      $asset_storage = $this->entityTypeManager->getStorage('asset');

      // Query the database for active materials.
      $asset_query = $asset_storage->getQuery()
        ->accessCheck(TRUE) // Check the user's access to the materials.
        ->condition('status', 'active')
        ->condition('type', 'material');
      $asset_ids = $asset_query->execute(); // Execute the query and get the asset IDs.

      // Load the materials and build an array of options.
      $options = [];
      if (!empty($asset_ids)) {
        $materials = $asset_storage->loadMultiple($asset_ids);
        foreach ($materials as $material) {
          $options[$material->id()] = $material->label();
        }
        asort($options);
      }

      return $options;
    } catch (\Exception $e) {
      // Log the error and return an empty array.
      $this->logger->error('Failed to load material assets: @message', [
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['log_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Log Name'),
      '#required' => TRUE,
      '#description' => $this->t('Log name/description of event.'),
    ];

    $form['date_start'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Operation start time and date'),
      '#required' => TRUE,
      '#description' => $this->t('The start date and time of the operation.'),
    ];

    $form['date_end'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Operation finish time and date'),
      '#required' => TRUE,
      '#description' => $this->t('The finish date and time of the operation.'),
    ];

    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Job Status'),
      '#options' => [
        'done' => $this->t('Done'),
        'pending' => $this->t('Pending'),
      ],
      '#default_value' => 'done',
      '#required' => TRUE,
      '#description' => $this->t('The current status of the job.'),
    ];

    $form['flag'] = [
      '#type' => 'select',
      '#title' => $this->t('Flag'),
      '#options' => [
        'monitor' => $this->t('Monitor'),
        'priority' => $this->t('Priority'),
        'needs_review' => $this->t('Needs Review'),
      ],
      '#multiple' => TRUE,
      '#description' => $this->t('Flag this job if it is a priority, requires monitoring or review.'),
    ];

    // Users to assign.
    $users = $this->getUserOptions($this->entityTypeManager);
    $form['assigned_to'] = [
      '#type' => 'select',
      '#title' => $this->t('Assigned to'),
      '#options' => $users,
      '#multiple' => TRUE,
      '#required' => TRUE,
      '#description' => $this->t('The operator(s) who carried out the task.'),
    ];

    $materials = $this->getMaterials();
    $form['product'] = [
      '#type' => 'select',
      '#title' => $this->t('Product'),
      '#options' => $materials,
      '#required' => TRUE,
      '#description' => $this->t('The product used.'),
    ];
    
    $form['product_rate'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Product Rate'),
      '#description' => $this->t('The rate the product is applied per unit area.'),
      '#target_type' => 'material_type',
      '#required' => TRUE,
    ];

    $form['total_product_quantity'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Total Product Quantity'),
      '#description' => $this->t('The total amount of product required to cover the field area(s).'),
      '#target_type' => 'material',
      '#selection_settings' => [
        'target_bundles' => ['assets' => 'material'],
      ],
      '#required' => TRUE,
    ];

    $form['water_volume'] = [
      '#type' => 'number',
      'title' => $this->t('Water Volume'),
        '#required' => TRUE,
        'description' => $this->t('If the full tank used enter zero. If not, estimate or calculate the remaining.'),
        'measure' => ['#value' => 'volume'],
        'units' => ['#options' => ['l' => 'l','gal' => 'gal']],
    ];

    $form['area'] = [
      'title' => $this->t('Area'),
      'description' => $this->t('The total area being sprayed.'),
      'measure' => ['#value' => 'area'],
      'units' => ['#options' => ['m2' => 'm2', 'ha' => 'ha']],
    ];

    $form['wind_speed'] = [
      'title' => $this->t('Wind Speed'),
      '#required' => TRUE,
      'description' => $this->t('The maximum wind speed during spraying.'),
      'measure' => ['#value' => 'ratio'],
      'units' => ['#options' => ['kph' => 'kph', 'mph' => 'mph']],
    ];

    // Wind direction.
    $wind_directions = [
      $this->t('North'),
      $this->t('South'),
      $this->t('East'),
      $this->t('West'),
      $this->t('North East'),
      $this->t('North West'),
      $this->t('South East'),
      $this->t('South West'),
    ];

    $wind_direction_options = array_combine($wind_directions, $wind_directions);
    $form['wind_direction'] = [
      '#type' => 'select',
      '#title' => $this->t('Wind Direction'),
      '#description' => $this->t('The dominant wind direction during spraying. Please select the general direction the wind is coming from.'),
      '#options' => $wind_direction_options,
      '#required' => TRUE,
    ];

      // Temperature (Degrees C).
    // $form['temperature'] = [
    //   'title' => $this->t('Temperature (C)'),
    //   'description' => $this->t('The average temperature during spraying.'),
    //   'measure' => ['#value' => 'temperature'],
    //   'units' => ['#value' => 'C'],
    //   'required' => TRUE,
    // ];

    // Weather types.
    $weather_types = [
      $this->t('Cloudy'),
      $this->t('Partially cloudy'),
      $this->t('Clear'),
      $this->t('Dry'),
      $this->t('Light rain'),
      $this->t('Heavy rain'),
      $this->t('Snow'),
      $this->t('Ice'),
      $this->t('Frost'),
      $this->t('Thunderstorms'),
    ];

    $weather_types_options = array_combine($weather_types, $weather_types);
    $form['weather'] = [
      '#type' => 'select',
      '#title' => $this->t('Weather'),
      '#description' => $this->t('The dominant weather conditions during spraying.'),
      '#options' => $weather_types_options,
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];

    // $form['pressure'] = [
    //   '#type' => 'number',
    //   'title' => $this->t('Pressure'),
    //   'description' => $this->t('The water pressure used when applying the product, where relevant.'),
    //   'measure' => ['#value' => 'pressure'],
    //   'units' => ['#value' => 'bar'],
    // ];

    $equipments = $this->getActiveEquipmentLabels();

    $form['equipment'] = [
      '#type' => 'select',
      '#title' => $this->t('Equipment'),
      '#description' => $this->t('Select the tractor, sprayer and nozzle equipment assets used for this spraying'),  
      '#options' => $equipments,
      '#required' => TRUE,
      '#multiple' => TRUE,
    ];

    $form['justification_target'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Justification/Target'),
      '#description' => $this->t('The reason the operation is necessary, and any target pest(s) where applicable.'),
      '#required' => TRUE,
    ];

    $form['plant_growth_stage'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Plant growth stage'),
      '#description' => $this->t('The plant growth stage when the product was applied.'),
      '#required' => FALSE,
    ];

    $form['equipment_rinsed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Equipment triple rinsed'),
      '#description' => $this->t('Select if the equipment was triple rinsed after the job was completed.'),
      '#return_value' => 'Yes',
    ];

    $form['equipment_clear'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Equipment All Clear'),
      '#description' => $this->t('Select if the equipment all clear after the job was completed.'),
      '#return_value' => 'Yes',
    ];

    $form['equipment_washed'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Equipment Clear Washed'),
      '#description' => $this->t('Select if the equipment was clear washed after the job was completed.'),
      '#return_value' => 'Yes',
    ];

    return $form;
  }

  /**
  * Submit handler for Saaten Union Spraying Quick Form.
  */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Create a new log entity.
    $log = $this->createLog([
      'type' => "input",
      'name' => $form_state->getValue('log_name'),
      'timestamp' =>  $form_state->getValue('date_start'),
      'status' => $form_state->getValue('status'),
      'flag' => $form_state->getValue('flag'),
    ]);

    // Save the log entity.
    $log->save();

    // Display a success message.
    $this->messenger()->addMessage($this->t('The spraying log has been saved.'));
  }

}