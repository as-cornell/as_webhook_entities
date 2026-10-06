<?php

namespace Drupal\as_webhook_entities\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\as_webhook_entities\WebhookQueueDrainer;
use Drupal\Component\Utility\Html;
use Drupal\key\KeyRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defines a controller for managing webhook notifications.
 */
class WebhookEntitiesController extends ControllerBase {

  /**
   * The HTTP request object.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The queue factory.
   *
   * @var Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Processes queued notifications after the response is sent.
   *
   * @var \Drupal\as_webhook_entities\WebhookQueueDrainer
   */
  protected $drainer;

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a ASWebhookEntitiesController object.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   * @param \Drupal\Core\Queue\QueueFactory $queue
   *   The queue factory.
   * @param \Drupal\as_webhook_entities\WebhookQueueDrainer $drainer
   *   The queue drainer.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   */
  public function __construct(Request $request, QueueFactory $queue, WebhookQueueDrainer $drainer, KeyRepositoryInterface $key_repository) {
    $this->request = $request;
    $this->queueFactory = $queue;
    $this->drainer = $drainer;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('queue'),
      $container->get('as_webhook_entities.queue_drainer'),
      $container->get('key.repository')
    );
  }

  /**
   * Listens for webhook notifications and queues them for processing.
   *
   * @return Symfony\Component\HttpFoundation\Response
   *   Webhook providers typically expect an HTTP 200 (OK) response.
   */
  public function listener() {
    // Prepare the response.
    $response = new Response();
    $response->setContent('Notification received');

    // Capture the contents of the notification (payload).
    $payload = $this->request->getContent();

    // An empty body is a follow-up drain request from this site itself (see
    // WebhookQueueDrainer::continueElsewhere()), not a notification.
    $follow_up = trim($payload) === '';
    if (!$follow_up) {
      $this->queueFactory->get('webhook_entities_processor')->createItem($payload);
    }

    // Process after the response is sent, in a batch with anything else that
    // arrives meanwhile. This replaces running a full cron in the request,
    // which made the sender wait and hit its cURL timeout.
    if ($this->drainer->isEnabled()) {
      $this->drainer->request(!$follow_up);
    }

    // Respond with the success message.
    return $response;
  }

  /**
   * Checks access for incoming webhook notifications.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access() {
    // Get the access token from the headers.
    $incoming_token = $this->request->headers->get('Authorization');

    // Retrieve the token value from Key module.
    $key = $this->keyRepository->getKey('as_webhook_entities_token');
    $stored_token = $key ? $key->getKeyValue() : NULL;

    // If no token is configured, deny access.
    if (empty($stored_token)) {
      \Drupal::logger('as_webhook_entities')->error('Webhook authorization token is not configured. Configure it at /admin/config/services/webhook-entities');
      return AccessResult::forbidden('Authorization token not configured');
    }

    // Compare the stored token value to the token in each notification.
    // If they match, allow access to the route.
    return AccessResult::allowedIf($incoming_token === $stored_token);
  }

}