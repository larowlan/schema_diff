<?php

declare(strict_types=1);

namespace Drupal\schema_diff\Controller;

use Drupal\Component\Diff\Diff;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Diff\DiffFormatter;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Defines a controller for showing a schema diff.
 */
final class SchemaDiffController extends SqlContentEntityStorageSchema implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Constructs a new SchemaDiffController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   Database.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Entity field manager.
   */
  public function __construct(
    EntityTypeManagerInterface  $entityTypeManager,
    Connection                  $database,
    EntityFieldManagerInterface $entityFieldManager,
    protected EntityLastInstalledSchemaRepositoryInterface $entityLastInstalledSchemaRepository,
    protected DiffFormatter $diffFormatter) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->entityFieldManager = $entityFieldManager;
    $this->diffFormatter->show_header = FALSE;
  }

  /**
   * {@inheritdoc}
   */
  final public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('entity_field.manager'),
      $container->get('entity.last_installed_schema.repository'),
      $container->get('diff.formatter'),
    );
  }

  /**
   * Inits the given entity-type ID.
   *
   * @param string $entity_type_id
   *   Entity type ID
   */
  protected function initEntityTypeId(string $entity_type_id): void {
    $this->storage = $this->entityTypeManager->getStorage($entity_type_id);
    $this->entityType = $this->entityTypeManager->getActiveDefinition($entity_type_id);
    $this->fieldStorageDefinitions = $this->entityFieldManager->getActiveFieldStorageDefinitions($entity_type_id);
  }

  /**
   * Shows field schema diff for a field.
   *
   * @param string $entity_type
   *   Entity type.
   * @param string $field_name
   *   Field name.
   *
   * @return array
   *   Render array.
   */
  public function fieldSchemaDiff(string $entity_type_id, string $field_name): array {
    $this->initEntityTypeId($entity_type_id);
    $storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
    $original_storage_definitions = $this->entityLastInstalledSchemaRepository->getLastInstalledFieldStorageDefinitions($entity_type_id);
    if (!isset($storage_definitions[$field_name]) || !isset($original_storage_definitions[$field_name])) {
      throw new NotFoundHttpException();
    }
    return $this->buildFieldDifference($storage_definitions[$field_name], $original_storage_definitions[$field_name]);
  }

  /**
   * Checks if the changes to the storage definition requires schema changes.
   *
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $storage_definition
   *   The updated field storage definition.
   * @param \Drupal\Core\Field\FieldStorageDefinitionInterface $original
   *   The original field storage definition.
   *
   * @return array
   *   Detailed difference.
   */
  protected function buildFieldDifference( FieldStorageDefinitionInterface $storage_definition, FieldStorageDefinitionInterface $original) {
    $table_mapping = $this->getTableMapping($this->entityType);

    $this->diffFormatter->show_header = FALSE;

    $build = [];
    $build['#title'] = $this->t('View difference');
    // Add the CSS for the inline diff.
    $build['#attached']['library'][] = 'system/diff';

    $installed_schema = $this->loadFieldSchemaData($original);
    $schema = $this->getSchemaFromStorageDefinition($storage_definition);
    $this->processFieldStorageSchema($installed_schema);
    $this->processFieldStorageSchema($schema);
    $before = [
      'Has custom storage' => $original->hasCustomStorage(),
      'Schema' => $original->getSchema(),
      'Is revisionable' => $original->isRevisionable(),
      'Allows shared table storage' => $table_mapping->allowsSharedTableStorage($original),
      'Requires dedicated table storage' => $table_mapping->requiresDedicatedTableStorage($original),
      'Installed schema' => $installed_schema,
    ];
    $after = [
      'Has custom storage' => $storage_definition->hasCustomStorage(),
      'Schema' => $storage_definition->getSchema(),
      'Is revisionable' => $storage_definition->isRevisionable(),
      'Allows shared table storage' => $table_mapping->allowsSharedTableStorage($storage_definition),
      'Requires dedicated table storage' => $table_mapping->requiresDedicatedTableStorage($storage_definition),
      'Installed schema' => $schema,
    ];

    // We encode them to YAML because the diff is easier to read than JSON.
    $before_printed = Yaml::encode($before);
    $after_printed = Yaml::encode($after);
    $diff = new Diff(explode("\n", $before_printed), explode("\n", $after_printed));
    $build['diff'] = [
      '#type' => 'table',
      '#header' => [
        ['data' => $this->t('Installed'), 'colspan' => '2'],
        ['data' => $this->t('Defined'), 'colspan' => '2'],
      ],
      '#rows' => $this->diffFormatter->format($diff),
    ];

    $build['diff']['#rows'][] = [
      [
        'data' => [
          '#type' => 'inline_template',
          '#template' => '<pre><code>{{ output }}</code></pre>',
          '#context' => [
            'output' => $before_printed,
          ],
        ],
        'colspan' => 2,
      ],
      [
        'data' => [
          '#type' => 'inline_template',
          '#template' => '<pre><code>{{ output }}</code></pre>',
          '#context' => [
            'output' => $after_printed,
          ],
        ],
        'colspan' => 2,
      ],
    ];

    return $build;
  }

}
