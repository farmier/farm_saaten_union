<?php

namespace Drupal\farm_saaten_union\Plugin\QuickForm;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickLogTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Psr\Container\ContainerInterface;

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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MessengerInterface $messenger, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $messenger);
    $this->entityTypeManager = $entity_type_manager;
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
    );
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

    $form['start_date'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Operation start time and date'),
      '#required' => TRUE,
      '#description' => $this->t('The start date and time of the operation.'),
      '#default_value' => new DrupalDateTime('midnight'),
    ];

    $form['end_date'] = [
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

    // Flags.
    $flag_options = farm_flag_options('log', ['input']);
    $form['flag'] = [
      '#type' => 'select',
      '#title' => $this->t('Flag'),
      '#options' => $flag_options,
      '#multiple' => TRUE,
      '#description' => $this->t('Flag this job if it is a priority, requires monitoring or review.'),
    ];

    // Users to assign.
    $users = $this->getUserOptions();
    $form['assigned_to'] = [
      '#type' => 'select',
      '#title' => $this->t('Assigned to'),
      '#options' => $users,
      '#multiple' => TRUE,
      '#required' => TRUE,
      '#description' => $this->t('The operator(s) who carried out the task.'),
      '#default_value' => \Drupal::currentUser()->id(),
    ];

    # Number of products.
    $range = range(1, 10);
    $form['product_count'] = [
      '#type' => 'select',
      '#title' => $this->t('How many products were applied?'),
      '#options' => array_combine($range, $range),
      '#default_value' => 1,
      '#ajax' => [
        'callback' => [$this, 'productsCallback'],
        'wrapper' => 'products',
      ],
    ];

    // Create a container for products.
    $form['products'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#attributes' => ['id' => 'products'],
    ];

    // Create a fieldset for each set of product information.
    $product_count = $form_state->getValue('product_count', 1);
    for ($i = 0; $i < $product_count; $i++) {
      $counter = ' ' . ($i + 1);
      $form['products'][$i] = [
        '#type' => 'details',
        '#title' => $this->t('Product') . $counter,
        '#open' => TRUE,
      ];

      $materials = $this->getMaterials();
      $form['products'][$i]['product'] = [
        '#type' => 'select',
        '#title' => $this->t('Product'),
        '#options' => $materials,
        '#required' => TRUE,
        '#description' => $this->t('The product used.'),
      ];

      $form['products'][$i]['product_rate'] = $this->buildQuantityField([
        'title' => $this->t('Product Rate'),
        'description' => $this->t('The rate the product is applied per unit area.'),
        'required' => TRUE,
        'type' => ['#value' => 'material'],
        'measure' => ['#value' => 'rate'],
        'units' => [
          '#options' => [
            'l/ha' => 'l/ha',
            'kg/ha' => 'kg/ha',
          ]
        ],
      ]);

      $form['products'][$i]['total_product_quantity'] = $this->buildQuantityField([
        'title' => $this->t('Total Product Quantity'),
        'description' => $this->t('The total amount of product required to cover the field area(s).'),
        'required' => TRUE,
        'type' => ['#value' => 'material'],
        'measure' => ['#value' => ''],
        'units' => [
          '#options' => [
            'l' => 'l',
            'kg' => 'kg',
            'ml' => 'ml'
          ]
        ],
      ]);
    }

    // Water Volume.
    $water_volume = [
      'title' => $this->t('Water Volume'),
      'description' => $this->t('The total amount of water required to cover the field area(s).'),
      'measure' => ['#value' => 'volume'],
      'units' => ['#value' => 'l'],
      'required' => TRUE,
    ];
    $form['water_volume']  = $this->buildQuantityField($water_volume);

    // Area sprayed.
    $area_sprayed = [
      'title' => $this->t('Area sprayed'),
      'description' => $this->t('The total area being sprayed.'),
      'measure' => ['#value' => 'area'],
      'units' => ['#value' => 'ha'],
    ];
    $form['area'] = $this->buildQuantityField($area_sprayed);

    // Wind speed.
    $wind_speed = [
      'title' => $this->t('Wind speed'),
      'description' => $this->t('The maximum wind speed during spraying.'),
      'measure' => ['#value' => ''],
      'units' => ['#value' => 'km/h'],
      'required' => TRUE,
    ];
    $form['wind_speed'] = $this->buildQuantityField($wind_speed);

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

    $form['temperature'] = $this->buildQuantityField([
      'title' => $this->t('Temperature (C)'),
      'description' => $this->t('The average temperature during spraying.'),
      'measure' => ['#value' => 'temperature'],
      'units' => ['#value' => '°C'],
      'required' => TRUE,
    ]);

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

    $form['pressure'] = $this->buildQuantityField([
      'title' => $this->t('Pressure'),
      'description' => $this->t('The water pressure used when applying the product, where relevant.'),
      'measure' => ['#value' => 'pressure'],
      'units' => ['#value' => 'bar'],
    ]);

    // Speed driven.
    $form['speed_driven'] = $this->buildQuantityField([
      'title' => $this->t('Speed Driven'),
      'description' => $this->t('The traveling speed when spraying, where relevant.'),
      'measure' => ['#value' => ''],
      'units' => ['#value' => 'km/h'],
    ]);

    // Nozzle type.
    $form['nozzle_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nozzle type'),
      '#description' => $this->t('The type of spray nozzle used, where relevant'),
    ];

    $form['location'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('Location'),
      '#description' => $this->t('The location of this spraying event.'),
      '#target_type' => 'asset',
      '#selection_handler' => 'views',
      '#selection_settings' => [
        'view' => [
          'view_name' => 'farm_location_reference',
          'display_name' => 'entity_reference',
          'arguments' => [],
        ],
        'match_operator' => 'CONTAINS',
      ],
      '#required' => TRUE,
    ];

    $plant_asset_options = $this->getPlantAssetOptions();
    $form['plant_asset'] = [
      '#type' => 'select',
      '#title' => $this->t('Plant asset'),
      '#description' => $this->t('The plant asset that this log relates to.'),
      '#options' => $plant_asset_options,
      '#multiple' => TRUE,
    ];

    $equipment_options = $this->getEquipmentOptions();
    $form['equipment'] = [
      '#type' => 'select',
      '#title' => $this->t('Equipment'),
      '#description' => $this->t('Select the tractor, sprayer and nozzle equipment assets used for this spraying'),
      '#options' => $equipment_options,
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

    // Log notes.
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#description' => $this->t('Any additional notes.'),
      '#weight' => 20,
    ];

    return $form;
  }

  /**
   * Submits the form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Load asset storage.
    $asset_storage = $this->entityTypeManager->getStorage('asset');

    // Build product quantities.
    $product_quantities = [];
    foreach ($form_state->getValue('products') as $product) {

      // Get selected material asset from the form.
      $material = $asset_storage->load($product['product']);

      // Get the material type.
      $material_type = $material->get('material_type');

      // Iterate over the product quantity fields and build quantities.
      $product_quantity_keys = [
        'product_rate',
        'total_product_quantity',
      ];
      foreach ($product_quantity_keys as $field_key) {
        $quantity = $product[$field_key];

        // Add the material type.
        $quantity['material_type'] = $material_type;

        // Decrement the material asset inventory by the total quantity used.
        if ($field_key === 'total_product_quantity') {
          $quantity['inventory_adjustment'] = 'decrement';
          $quantity['inventory_asset'] = $material;
        }

        if (is_array($quantity) && is_numeric($quantity['value'])) {
          $product_quantities[] = $quantity;
        }
      }
    }

    // Build other quantities.
    $other_quantities = [];
    $quantity_keys = [
      'water_volume',
      'area',
      'wind_speed',
      'temperature',
      'pressure',
      'speed_driven'
    ];
    foreach ($quantity_keys as $field_key) {
      $quantity = $form_state->getValue($field_key);
      if (is_array($quantity) && is_numeric($quantity['value'])) {
        $other_quantities[] = $quantity;
      }
    }

    // Notes.
    $note_fields = [
      // Prepend additional note fields.
      [
        'key' => 'end_date',
        'label' => $this->t('End Date'),
      ],
      [
        'key' => 'nozzle_type',
        'label' => $this->t('Nozzle type'),
      ],
      [
        'key' => 'justification_target',
        'label' => $this->t('Justification/Target'),
      ],
      [
        'key' => 'plant_growth_stage',
        'label' => $this->t('Plant Growth Stage'),
      ],
      [
        'key' => 'weather',
        'label' => $this->t('Weather'),
      ],
      [
        'key' => 'wind_direction',
        'label' => $this->t('Wind direction'),
      ],
      [
        'key' => 'equipment_rinsed',
        'label' => $this->t('Equipment triple-rinsed'),
      ],
      [
        'key' => 'equipment_clear',
        'label' => $this->t('Equipment all clear'),
      ],
      [
        'key' => 'equipment_washed',
        'label' => $this->t('Equipment clear washed'),
      ],
      [
        'key' => 'notes',
        'label' => $this->t('Additional notes'),
      ],
    ];
    $notes = $this->prepareNotes($note_fields, $form_state);

    // Load asset storage.
    $asset_storage = $this->entityTypeManager->getStorage('asset');
    $plant_field = array_values($form_state->getValue('plant_asset'));
    $assets = $asset_storage->loadMultiple($plant_field);
    $location_field = array_values($form_state->getValue('location'));
    $locations = $asset_storage->loadMultiple($location_field);

    // Input log entity.
    $this->createLog([
      'type' => "input",
      'name' => $form_state->getValue('log_name'),
      'timestamp' => $form_state->getValue('start_date')->getTimestamp(),
      'status' => $form_state->getValue('status'),
      'flag' => array_values($form_state->getValue('flag')),
      'owner' => $form_state->getValue('assigned_to'),
      'equipment' => $form_state->getValue('equipment'),
      'quantity' => array_merge($product_quantities, $other_quantities),
      'asset' => $assets,
      'location' => $locations,
      'notes' => $notes
    ]);

  }

/**
 * Retrieves a sorted list of active, non-admin user labels.
 *
 * @return array
 *   An array of user labels indexed by user ID and sorted alphabetically.
 */
protected function getUserOptions(): array {
  // Query active, non-admin users.
  $userQuery = $this->entityTypeManager->getStorage('user')->getQuery();
  $userQuery->accessCheck(TRUE);
  $userQuery->condition('status', 1);
  $userQuery->condition('uid', '1', '>');

  // Load users.
  $userIds = $userQuery->execute();
  $users = $this->entityTypeManager->getStorage('user')->loadMultiple($userIds);

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
*   An array of equipment options indexed by asset id and sorted alphabetically.
*/
protected function getEquipmentOptions(): array {
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
  $equipment_options = array_map(function ($equipment) {
    return $equipment->label();
  }, $equipments);
  natcasesort($equipment_options);

  return $equipment_options;
}

/**
 * Helper function to get the labels of active material assets, sorted alphabetically.
 *
 * @return string[]
 *   An array of material labels indexed by asset id and sorted alphabetically.
 */
protected function getMaterials(): array {
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
  }

/**
 * Helper function to get the labels of active plant assets, sorted alphabetically.
 *
 * @return string[]
 *   An array of plant labels indexed by asset id and sorted alphabetically.
 */
protected function getPlantAssetOptions(): array {
  // Get the entity storage for the 'asset' entity type.
  $asset_storage = $this->entityTypeManager->getStorage('asset');

  // Query the database for active materials.
  $asset_query = $asset_storage->getQuery()
    ->accessCheck(TRUE) // Check the user's access to the materials.
    ->condition('status', 'active')
    ->condition('type', 'plant');
  $asset_ids = $asset_query->execute(); // Execute the query and get the asset IDs.

  // Load the materials and build an array of options.
  $options = [];
  if (!empty($asset_ids)) {
    $plants = $asset_storage->loadMultiple($asset_ids);
    foreach ($plants as $plant) {
      $options[$plant->id()] = $plant->label();
    }
    asort($options);
  }

  return $options;
}

  /**
   * Helper function to build a render array for a quantity field.
   *
   * @param array $config
   *   Configuration for the quantity field.
   *
   * @return array
   *   Render array for the quantity field.
   */
  public function buildQuantityField(array $config = []) {
    // Default the label to the fieldset title.
    if (!empty($config['title']) && empty($config['label'])) {
      $config['label']['#value'] = (string) $config['title'];
    }

    // Auto-hide fields if #value is provided and no #type is specified.
    foreach (['measure', 'value', 'units', 'label'] as $field_name) {
      if (isset($config[$field_name]['#value']) && !isset($config[$field_name]['#type'])) {
        $config[$field_name]['#type'] = 'hidden';
      }
    }

    // Auto-populate the unit #options if the #value is specified.
    if (isset($config['units']['#value']) && empty($config['units']['#options'])) {
      $default_unit = $config['units']['#value'];
      $config['units']['#options'] = [$default_unit => $default_unit];
    }

    // Default config.
    $default_config = [
      'border' => FALSE,
      'type' => [
        '#type' => 'hidden',
        '#value' => 'standard',
      ],
      'measure' => [
        '#type' => 'select',
        '#title' => $this->t('Measure'),
        '#options' => quantity_measure_options(),
        '#weight' => 0,
      ],
      'value' => [
        '#type' => 'number',
        '#weight' => 5,
        '#min' => 0,
        '#step' => 0.01,
      ],
      'units' => [
        '#weight' => 10,
      ],
      'label' => [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#weight' => 15,
        '#size' => 15,
      ],
    ];
    $config = array_replace_recursive($default_config, $config);

    // Start a render array with a fieldset.
    $render = [
      '#type' => 'fieldset',
      '#tree' => TRUE,
      '#theme_wrappers' => ['fieldset'],
      '#attributes' => [
        'class' => ['inline-quantity', 'container-inline'],
      ],
      '#attached' => [
        'library' => ['farm_saaten_union/quantity_fieldset'],
      ],
    ];

    // Configure the top level fieldset.
    foreach (['title', 'description'] as $key) {
      if (!empty($config[$key])) {
        $render["#$key"] = $config[$key];
      }
    }

    // Include each quantity subfield.
    $render['type'] = $config['type'];
    $render['measure'] = $config['measure'];
    $render['value'] = $config['value'];
    $render['label'] = $config['label'];

    // Save units to a variable for now.
    // The key may be saved as units or units_id.
    $units_key_name = 'units';
    $units = $config['units'];

    // Check if unit options are provided.
    if (!empty($units['#options'])) {
      $units_options = $units['#options'];

      // If a numeric value is provided, assume these are term ids.
      if (is_numeric(key($units_options))) {
        $units_key_name = 'units_id';
      }

      // Render the units as select options.
      $units += [
        '#type' => 'select',
        '#options' => $units_options,
      ];

      // If the unit value is hard-coded add a field suffix to the value field
      // with the first option label.
      if (isset($units['#value'])) {
        $render['value']['#field_suffix'] = current($units_options);
      }
    }
    // Else default to entity_autocomplete unit terms. Use the units_id key.
    else {
      $units_key_name = 'units_id';
      // Add entity_autocomplete.
      $units += [
        '#type' => 'entity_autocomplete',
        '#placeholder' => $this->t('Units'),
        '#target_type' => 'taxonomy_term',
        '#selection_handler' => 'default',
        '#selection_settings' => [
          'target_bundles' => ['unit'],
        ],
        '#tags' => FALSE,
        '#size' => 15,
      ];
    }

    // Include units in render array.
    $render[$units_key_name] = $units;

    // Check if the quantity is required.
    if (!empty($config['required'])) {
      $render['#required'] = TRUE;
      $render['value']['#required'] = TRUE;
    }

    // Remove the border if needed.
    if (empty($config['border'])) {
      $render['#attributes']['class'][] = 'no-border';
    }

    return $render;
  }

  /**
   * Prepares an array of note strings.
   *
   * @param array $note_fields
   *  An array of note fields.
   * @param FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   * An array of note strings, with each string formatted as follows:
   * "[label]: [value]".
   */
  protected function prepareNotes(array $note_fields, FormStateInterface $form_state): array {

    // Start an array of note strings.
    $notes = [];

    // Build note string.
    foreach ($note_fields as $field_info) {
      $key = $field_info['key'] ?? NULL;
      if (!empty($key) && $form_state->hasValue($key) && !$form_state->isValueEmpty($key)) {
        $note_value = $form_state->getValue($key);
        // If the note value is an array, join its elements with commas.
        $note_value = is_array($note_value) ? implode(', ', $note_value) : $note_value;
        $notes[] = $field_info['label'] . ': ' . $note_value;
      }
    }

    // Return the array of note strings, with each note string separated by a new line.
    return [
      'value' => implode(PHP_EOL, $notes),
      'format' => 'default',
    ];
  }

  /**
   * Ajax callback for products fields.
   */
  public function productsCallback(array $form, FormStateInterface $form_state) {
    return $form['products'];
  }

}
