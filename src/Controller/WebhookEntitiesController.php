<?php

namespace Drupal\as_webhook_entities\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\ProxyClass\Cron;
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
   * The cron service.
   *
   * @var \Drupal\Core\Cron
   */
  protected $cron;

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
   * @param \Drupal\Core\Cron $cron
   *   The cron service.
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   */
  public function __construct(Request $request, QueueFactory $queue, Cron $cron, KeyRepositoryInterface $key_repository) {
    $this->request = $request;
    $this->queueFactory = $queue;
    $this->cron = $cron;
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack')->getCurrentRequest(),
      $container->get('queue'),
      $container->get('cron'),
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

    // Get the queue implementation.
    $queue = $this->queueFactory->get('webhook_entities_processor');

    // Add the $payload to the queue.
    $queue->createItem($payload);

    // Run cron for immediate gratification
    // check config to see if we want to trigger cron
    $crontrigger = \Drupal::config('as_webhook_entities.settings')->get('crontrigger');
    //check to see if there's an active cron run
    $cronlock = \Drupal::lock()->acquire('cron', 0.0);
    // release the cron lock we just did as a test
    \Drupal::lock()->release('cron');
    
    if ($crontrigger == TRUE && $cronlock == TRUE ){
    //run cron
    $this->cron->run();
    // log a message for debug
    \Drupal::logger('as_webhook_entities')
            ->info('Cron run was triggered by WebhookEntitiesController. crontrigger was: '. json_encode($crontrigger).' cronlock was: '.json_encode($cronlock).'.');
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