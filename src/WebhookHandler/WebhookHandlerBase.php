<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Abstract base class for webhook entity type handlers.
 *
 * Provides shared helper methods for taxonomy term and node lookups.
 * Subclasses override applyCreateFields() and/or applyUpdateFields() as needed.
 */
abstract class WebhookHandlerBase implements WebhookHandlerInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a WebhookHandlerBase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public function applyCreateFields(array &$node_values, object $entity_data, array $domain_schema): void {
    // No-op by default. Subclasses override as needed.
  }

  /**
   * {@inheritdoc}
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void {
    // No-op by default. Subclasses override as needed.
  }

  /**
   * Looks up taxonomy term tids from an array of term names.
   *
   * @param array $names
   *   An array of taxonomy term name strings.
   *
   * @return array
   *   An array of term IDs (tids) for terms matching the given names.
   */
  protected function lookupTermTidsByName(array $names): array {
    if (empty($names)) {
      return [];
    }
    $tids = [];
    foreach ($names as $name) {
      if (empty($name)) {
        continue;
      }
      $results = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $name]);
      foreach ($results as $term) {
        $tids[] = $term->id();
      }
    }
    return $tids;
  }

  /**
   * Looks up taxonomy term tids by a field property and array of values.
   *
   * @param string $field
   *   The field name to match against (e.g. 'field_people_tid').
   * @param array $values
   *   An array of field values to look up.
   *
   * @return array
   *   An array of term IDs (tids) for terms matching the given field values.
   */
  protected function lookupTermTidsByProperty(string $field, array $values): array {
    if (empty($values)) {
      return [];
    }
    $tids = [];
    foreach ($values as $value) {
      if (empty($value)) {
        continue;
      }
      $results = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties([$field => $value]);
      foreach ($results as $term) {
        $tids[] = $term->id();
      }
    }
    return $tids;
  }

  /**
   * Looks up node nids by field_remote_uuid values.
   *
   * @param array $uuids
   *   An array of remote UUID strings to look up.
   *
   * @return array
   *   An array of node IDs (nids) for nodes matching the given remote UUIDs.
   */
  protected function lookupNodeNidsByRemoteUuid(array $uuids): array {
    if (empty($uuids)) {
      return [];
    }
    $nids = [];
    foreach ($uuids as $uuid) {
      if (empty($uuid)) {
        continue;
      }
      $results = $this->entityTypeManager->getStorage('node')->loadByProperties(['field_remote_uuid' => $uuid]);
      foreach ($results as $node) {
        $nids[] = $node->id();
      }
    }
    return $nids;
  }

}
