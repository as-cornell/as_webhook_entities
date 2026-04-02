<?php

namespace Drupal\as_webhook_entities;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Class WebhookUuidLookup.
 *
 * Attempts to load an entity by the UUID received from a webhook notification.
 *
 */
class WebhookUuidLookup {

  /**
   * Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * WebhookUuidLookup constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity Type Manager service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }
  /**
   * Finds an existing entity matching the given UUID and type.
   *
   * @param string $uuid
   *   The remote UUID from the webhook payload.
   * @param string $type
   *   The webhook entity type (e.g., 'article', 'person', 'term').
   *
   * @return \Drupal\Core\Entity\EntityInterface|false
   *   The matched entity, or FALSE if none found.
   */
  public function findEntity($uuid, $type) {
    $lookup_map = [
      'article'             => ['node', 'field_remote_uuid'],
      'person'              => ['node', 'field_remote_uuid'],
      'media_report_entry'  => ['node', 'field_remote_uuid'],
      'media_report_person' => ['node', 'field_people_uuid'],
      'term'                => ['taxonomy_term', 'field_people_tid'],
    ];

    if (!isset($lookup_map[$type])) {
      return FALSE;
    }

    [$storage_type, $field] = $lookup_map[$type];
    $entities = $this->entityTypeManager->getStorage($storage_type)->loadByProperties([$field => $uuid]);
    return reset($entities) ?: FALSE;
  }
}