<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

use Drupal\Core\Entity\EntityInterface;

/**
 * Optional interface for handlers that must act once the node save resolves.
 *
 * Handlers build values before the node is saved, but some work can only be
 * done after: replaced composite entities cannot be purged until the node has
 * stopped referencing them, and entities built for a save that failed or never
 * happened need removing. WebhookCrudManager calls finishSave() after every
 * create and update attempt, whether or not the save went through.
 */
interface WebhookHandlerFinishSaveInterface {

  /**
   * Completes a create or update once the save has succeeded or failed.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The node, or NULL when a create failed before one existed.
   * @param bool $saved
   *   TRUE when the save succeeded.
   */
  public function finishSave(?EntityInterface $entity, bool $saved): void;

}
