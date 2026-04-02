<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

/**
 * Interface for webhook entity type handlers.
 *
 * Each implementation handles field mapping for one webhook payload type
 * (e.g. 'person', 'article') during entity create and update operations.
 */
interface WebhookHandlerInterface {

  /**
   * Returns the webhook payload type key this handler supports.
   *
   * @return string
   *   The type string, e.g. 'person' or 'article'.
   */
  public function getType(): string;

  /**
   * Adds type-specific fields to the $node_values array for node creation.
   *
   * @param array $node_values
   *   The node values array, passed by reference, to be populated.
   * @param object $entity_data
   *   The decoded webhook payload entity data object.
   * @param array $domain_schema
   *   Domain schema context array, typically containing 'domain' and 'schema'
   *   keys identifying the current site.
   */
  public function applyCreateFields(array &$node_values, object $entity_data, array $domain_schema): void;

  /**
   * Applies type-specific fields to an existing Drupal entity on update.
   *
   * @param object $existing_entity
   *   The existing Drupal entity to update.
   * @param object $entity_data
   *   The decoded webhook payload entity data object.
   * @param array $domain_schema
   *   Domain schema context array, typically containing 'domain' and 'schema'
   *   keys identifying the current site.
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void;

}
