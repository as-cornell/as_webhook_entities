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
//add type here called from entities queue
  public function findEntity($uuid,$type) {

    if ($type == 'person') {
    $entities = $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties(['field_remote_uuid' => $uuid]);
    }
    if ($type == 'article') {
    $entities = $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties(['field_remote_uuid' => $uuid]);
    }
    if ($type == 'media_report_person') {
    $entities = $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties(['field_people_uuid' => $uuid]);
    }
    if ($type == 'media_report_entry') {
    $entities = $this->entityTypeManager
      ->getStorage('node')
      ->loadByProperties(['field_remote_uuid' => $uuid]);
    }
    if ($type == 'term') {
    $entities = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties(['field_people_tid' => $uuid]);
    }

    if ($existing_entity = reset($entities)) {
      return $existing_entity;
    }

    return FALSE;
  }
}