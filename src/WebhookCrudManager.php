<?php

namespace Drupal\as_webhook_entities;

use Drupal\as_webhook_entities\WebhookHandler\ArticleWebhookHandler;
use Drupal\as_webhook_entities\WebhookHandler\MediaReportEntryWebhookHandler;
use Drupal\as_webhook_entities\WebhookHandler\MediaReportPersonWebhookHandler;
use Drupal\as_webhook_entities\WebhookHandler\PersonWebhookHandler;
use Drupal\as_webhook_entities\WebhookHandler\TermWebhookHandler;
use Drupal\as_webhook_entities\WebhookHandler\WebhookHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Thin dispatcher for webhook-driven entity CRUD operations.
 *
 * Receives decoded webhook payload data and delegates entity-type-specific
 * field mapping to WebhookHandlerInterface implementations via the Strategy
 * pattern. Common cross-type logic (title, status, departments, domain access)
 * is handled here; per-type field logic lives in the handler classes under
 * WebhookHandler/.
 */
class WebhookCrudManager {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Registered webhook handler instances, keyed by type string.
   *
   * @var \Drupal\as_webhook_entities\WebhookHandler\WebhookHandlerInterface[]
   */
  protected array $handlers;

  /**
   * Constructs a WebhookCrudManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->handlers = [
      'person'              => new PersonWebhookHandler($entity_type_manager),
      'article'             => new ArticleWebhookHandler($entity_type_manager),
      'media_report_entry'  => new MediaReportEntryWebhookHandler($entity_type_manager),
      'media_report_person' => new MediaReportPersonWebhookHandler($entity_type_manager),
      'term'                => new TermWebhookHandler($entity_type_manager, $logger),
    ];
  }

  /**
   * Returns the handler for the given type, or NULL if none is registered.
   *
   * @param string $type
   *   The webhook entity type key.
   *
   * @return \Drupal\as_webhook_entities\WebhookHandler\WebhookHandlerInterface|null
   *   The handler, or NULL.
   */
  protected function getHandler(string $type): ?WebhookHandlerInterface {
    return $this->handlers[$type] ?? NULL;
  }

  /**
   * Creates a new node entity using webhook notification data.
   *
   * @param object $entity_data
   *   Required data from the notification body.
   */
  public function createEntity($entity_data) {
    if (empty($entity_data->title)) {
      return;
    }

    $domain_schema = $this->getDomainSchema();
    $node_values = [
      'title'  => $entity_data->title,
      'status' => (bool) $entity_data->status,
      'uid'    => $entity_data->uid,
    ];

    $this->getHandler($entity_data->type)?->applyCreateFields($node_values, $entity_data, $domain_schema);
    $this->applyDepartmentsCreate($node_values, $entity_data, $domain_schema);

    try {
      $node = $this->entityTypeManager->getStorage('node')->create($node_values);
      $node->save();
      $this->logger->notice('Node @id created to represent webhook entity @uuid', [
        '@id' => $node->id(),
        '@uuid' => $entity_data->uuid,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->warning('A node could not be created to represent webhook entity @uuid. @error', [
        '@uuid' => $entity_data->uuid,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Creates a new taxonomy term entity using webhook notification data.
   *
   * Delegates to TermWebhookHandler::createTerm(), which manages term-specific
   * storage and logging.
   *
   * @param object $entity_data
   *   Required data from the notification body.
   */
  public function createTermEntity($entity_data) {
    $handler = $this->handlers['term'] ?? NULL;
    $handler?->createTerm($entity_data, $this->getDomainSchema());
  }

  /**
   * Updates an existing entity using webhook notification data.
   *
   * @param object $existing_entity
   *   The existing Drupal entity to update.
   * @param object $entity_data
   *   Required data from the notification body.
   */
  public function updateEntity($existing_entity, $entity_data) {
    $domain_schema = $this->getDomainSchema();
    $updated = FALSE;

    if (!empty($entity_data->title) && $entity_data->type !== 'term') {
      $existing_entity->title = $entity_data->title;
      $existing_entity->set('uid', $entity_data->uid);
      $existing_entity->set('status', (bool) $entity_data->status);
      $updated = TRUE;
    }
    elseif (!empty($entity_data->title)) {
      $updated = TRUE;
    }

    if ($entity_data->type !== 'term') {
      $existing_entity->field_portrait_image_path->value = $entity_data->field_portrait_image_path ?? NULL;
    }

    $this->getHandler($entity_data->type)?->applyUpdateFields($existing_entity, $entity_data, $domain_schema);
    $this->applyDepartmentsUpdate($existing_entity, $entity_data, $domain_schema);

    if ($updated) {
      $existing_entity->save();
      $this->logger->notice('Entity @id updated via webhook notification.', [
        '@id' => $existing_entity->id(),
        '@type' => $entity_data->type,
      ]);
    }
  }

  /**
   * Deletes an entity received via webhook notification.
   *
   * @param object $existing_entity
   *   The existing Drupal entity to delete.
   */
  public function deleteEntity($existing_entity) {
    $this->logger->notice('Entity @id deleted via webhook notification.', [
      '@id' => $existing_entity->id(),
    ]);
    $existing_entity->delete();
  }

  /**
   * Applies department/program and domain access fields during node creation.
   *
   * Looks up taxonomy terms for each department name in the payload and
   * populates field_departments_programs and (for departments schema)
   * field_domain_access.
   *
   * @param array $node_values
   *   The node values array, passed by reference.
   * @param object $entity_data
   *   The decoded webhook payload entity data object.
   * @param array $domain_schema
   *   Domain schema context array.
   */
  private function applyDepartmentsCreate(array &$node_values, object $entity_data, array $domain_schema): void {
    $is_departments = ($domain_schema['schema'] ?? '') === 'departments';
    $dparray = [];
    $daarray = [];
    foreach ((array) ($entity_data->field_departments_programs ?? []) as $dpname) {
      $results = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dpname]);
      if ($dp = reset($results)) {
        $dparray[] = $dp->get('tid')->value;
        if ($is_departments) {
          $daarray[] = $dp->get('field_domain_access_target_id')->value;
        }
      }
    }
    if (!empty($dparray)) {
      $node_values['field_departments_programs'] = $dparray;
    }
    if (!empty($daarray)) {
      sort($daarray);
      array_unshift($daarray, 'departments_as_cornell_edu');
      $node_values['field_domain_access'] = $daarray;
    }
  }

  /**
   * Applies department/program and domain access fields during entity update.
   *
   * Handles type-specific field names (e.g. media_report_entry uses
   * field_related_department_program; terms use domain_access vs
   * field_domain_access).
   *
   * @param object $existing_entity
   *   The existing Drupal entity to update.
   * @param object $entity_data
   *   The decoded webhook payload entity data object.
   * @param array $domain_schema
   *   Domain schema context array.
   */
  private function applyDepartmentsUpdate(object $existing_entity, object $entity_data, array $domain_schema): void {
    $type = $entity_data->type;
    $is_departments = ($domain_schema['schema'] ?? '') === 'departments';
    $dparray = [];
    $daarray = [];
    foreach ((array) ($entity_data->field_departments_programs ?? []) as $dpname) {
      $results = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dpname]);
      if ($dp = reset($results)) {
        $dparray[] = $dp->get('tid')->value;
        if ($is_departments) {
          $daarray[] = $dp->get('field_domain_access_target_id')->value;
        }
      }
    }
    if (!empty($dparray)) {
      if ($type !== 'term' && $type !== 'media_report_entry') {
        $existing_entity->set('field_departments_programs', $dparray);
      }
      elseif ($type === 'media_report_entry') {
        $existing_entity->set('field_related_department_program', $dparray);
      }
    }
    if (!empty($daarray)) {
      array_unshift($daarray, 'departments_as_cornell_edu');
      $existing_entity->set($type === 'term' ? 'domain_access' : 'field_domain_access', $daarray);
    }
  }

  /**
   * Determines the current domain schema from the request hostname.
   *
   * Returns an array with 'domain' (the current host) and optionally 'schema'
   * (one of 'as', 'departments', 'mediareport') if the host matches a known
   * site domain.
   *
   * @return array
   *   Domain schema context array with 'domain' and optionally 'schema' keys.
   */
  private function getDomainSchema(): array {
    $host = \Drupal::request()->getHost();
    $domain_schema = ['domain' => $host];
    $schemas = [
      'as' => [
        'artsci-as.lndo.site',
        'dev-artsci-as.pantheonsite.io',
        'test-artsci-as.pantheonsite.io',
        'live-artsci-as.pantheonsite.io',
        'as.cornell.edu',
      ],
      'departments' => [
        'artsci-departments.lndo.site',
        'dev-artsci-departments.pantheonsite.io',
        'test-artsci-departments.pantheonsite.io',
        'live-artsci-departments.pantheonsite.io',
        'departments.as.cornell.edu',
      ],
      'mediareport' => [
        'artsci-mediareport.lndo.site',
        'dev-artsci-mediareport.pantheonsite.io',
        'test-artsci-mediareport.pantheonsite.io',
        'live-artsci-mediareport.pantheonsite.io',
        'mediareport.as.cornell.edu',
      ],
    ];
    foreach ($schemas as $schema => $domains) {
      if (in_array($host, $domains)) {
        $domain_schema['schema'] = $schema;
        break;
      }
    }
    return $domain_schema;
  }

}
