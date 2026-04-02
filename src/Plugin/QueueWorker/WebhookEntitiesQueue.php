<?php

namespace Drupal\as_webhook_entities\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\as_webhook_entities\WebhookCrudManager;
use Drupal\as_webhook_entities\WebhookUuidLookup;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Process a queue of webhook notification payload data.
 *
 * @QueueWorker(
 *   id = "webhook_entities_processor",
 *   title = @Translation("Webhook notification processor"),
 *   cron = {"time" = 30}
 * )
 */
class WebhookEntitiesQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The default logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * CRUD service for entities managed via notifications.
   *
   * @var \Drupal\as_webhook_entities\WebhookCrudManager
   */
  protected $entityCrud;

  /**
   * The UUID lookup service.
   *
   * @var \Drupal\as_webhook_entities\WebhookUuidLookup
   */
  protected $uuidLookup;

  /**
   * Constructs a WebhookEntitiesQueue object.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\as_webhook_entities\WebhookCrudManager $crud_manager
   *   An instance of the custom entity CRUD manager.
   * @param \Drupal\as_webhook_entities\WebhookUuidLookup $uuid_lookup
   *   An instance of the UUID lookup service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, WebhookCrudManager $crud_manager, WebhookUuidLookup $uuid_lookup) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->entityCrud = $crud_manager;
    $this->uuidLookup = $uuid_lookup;
  }


  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      empty($configuration) ? [] : $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.channel.default'),
      $container->get('as_webhook_entities.crud_manager'),
      $container->get('as_webhook_entities.uuid_lookup')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($payload) {
    // Only process the payload if it contains data.

    if (!empty($payload)) {

      // Decode the JSON payload to a PHP object.
      $entity_data = json_decode($payload);

      // Only process the notification if it contains a UUID.
      if (isset($entity_data->uuid)) {

        // Determine whether an existing Drupal entity corresponds to the UUID.
        $existing_entity = $this->uuidLookup->findEntity($entity_data->uuid, $entity_data->type);

        // Handle create events.
        if ($entity_data->event == 'create') {
          if (!$existing_entity) {
            $this->dispatchCreate($entity_data);
          }
          else {
            $this->logger->warning('Webhook create notification received for UUID @uuid but corresponding entity @id already exists', [
              '@uuid' => $entity_data->uuid,
              '@id' => $existing_entity->id(),
            ]);
          }
        }
        // Handle update and delete events.
        else {
          if ($existing_entity) {
            switch ($entity_data->event) {
              case 'update':
                $this->entityCrud->updateEntity($existing_entity, $entity_data);
                break;

              case 'delete':
                $this->entityCrud->deleteEntity($existing_entity);
                break;
            }
          }
          // No existing entity — create one unless this is a delete event.
          elseif ($entity_data->event != 'delete') {
            $this->dispatchCreate($entity_data);
          }
        }
      }
      // Throw a warning if the payload doesn't contain a UUID.
      else {
        $this->logger->warning('Webhook notification received but not processed because UUID was missing', [
          '@uuid' => $entity_data->uuid,
        ]);
      }
    }
  }

  /**
   * Dispatches an entity creation to the appropriate CRUD method by type.
   *
   * @param object $entity_data
   *   The decoded webhook payload.
   */
  private function dispatchCreate(object $entity_data): void {
    $entity_data->type === 'term'
      ? $this->entityCrud->createTermEntity($entity_data)
      : $this->entityCrud->createEntity($entity_data);
  }

}