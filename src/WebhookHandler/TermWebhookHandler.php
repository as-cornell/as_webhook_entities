<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Webhook handler for the 'term' entity type.
 *
 * Manages taxonomy term creation and updates driven by webhook payloads.
 * Handles parent term lookups by field_people_tid and domain access mapping
 * for department-schema sites.
 */
class TermWebhookHandler extends WebhookHandlerBase {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs a TermWebhookHandler object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, LoggerInterface $logger) {
    parent::__construct($entityTypeManager);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'term';
  }

  /**
   * Creates a taxonomy term from webhook payload data.
   *
   * This method is called explicitly from WebhookCrudManager::createTermEntity()
   * rather than through the standard applyCreateFields() interface, because
   * terms are not nodes and require different storage handling.
   *
   * @param object $entity_data
   *   The decoded webhook payload entity data object.
   * @param array $domain_schema
   *   Domain schema context array, typically containing 'domain' and 'schema'
   *   keys identifying the current site.
   */
  public function createTerm(object $entity_data, array $domain_schema): void {
    if (empty($entity_data->title)) {
      return;
    }

    $term_values = [
      'name' => $entity_data->title,
      'vid' => $entity_data->vocabulary,
      'field_people_tid' => $entity_data->field_people_tid ?? NULL,
    ];

    // Parent lookup by field_people_tid.
    if (!empty($entity_data->parent)) {
      $results = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $entity_data->parent]);
      if ($parent = reset($results)) {
        $term_values['parent'] = $parent->get('tid')->value;
      }
    }

    // On departments schema: map domain access from department names.
    if (($domain_schema['schema'] ?? '') === 'departments') {
      $daarray = [];
      foreach ((array) ($entity_data->field_departments_programs ?? []) as $dpname) {
        $results = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dpname]);
        if ($dp = reset($results)) {
          $daarray[] = $dp->get('field_domain_access_target_id')->value;
        }
      }
      if (!empty($daarray)) {
        sort($daarray);
        array_unshift($daarray, 'departments_as_cornell_edu');
        $term_values['domain_access'] = $daarray;
      }
    }

    try {
      $entity = $this->entityTypeManager->getStorage('taxonomy_term')->create($term_values);
      $entity->save();
      $this->logger->notice('Entity @id created to represent webhook entity @type @uuid', [
        '@id' => $entity->id(),
        '@uuid' => $entity_data->uuid,
        '@type' => $entity_data->type,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->warning('An entity could not be created to represent webhook entity @uuid. @error', [
        '@uuid' => $entity_data->uuid,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void {
    $existing_entity->name = $entity_data->title;
    $existing_entity->set('vid', $entity_data->vocabulary);
    $existing_entity->set('field_people_tid', $entity_data->field_people_tid ?? NULL);

    // Parent lookup by field_people_tid.
    if (!empty($entity_data->parent)) {
      $results = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $entity_data->parent]);
      if ($parent = reset($results)) {
        $existing_entity->set('parent', $parent->get('tid')->value);
      }
    }
  }

}
