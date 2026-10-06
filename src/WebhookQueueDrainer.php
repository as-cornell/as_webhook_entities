<?php

namespace Drupal\as_webhook_entities;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\DelayableQueueInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Processes received notifications in batches, after the response is sent.
 *
 * The listener used to run a full cron for every notification, inside the
 * request, so the sending site waited on every cron task the receiver had and
 * hit its 30 second cURL timeout. Now the listener only queues the payload and
 * asks for a drain. The drain runs on kernel.terminate, which comes after the
 * response has gone out (PHP-FPM's fastcgi_finish_request), so the sender gets
 * its 200 straight away.
 *
 * Batching: one lock means one drainer at a time. A notification that arrives
 * while a drain is running just queues and returns, and the running drain picks
 * it up because it keeps claiming until the queue is empty. Search indexing for
 * the whole batch happens once at the end.
 *
 * Latency: a drain stops claiming after DRAIN_BUDGET seconds so it ends well
 * inside PHP's time limit. If anything is still queued then, it pings its own
 * listener with an empty body, which starts a fresh drain. Nothing waits for
 * the next cron run, so a queued notification is picked up within a minute
 * unless a single batch ahead of it takes longer than that to process.
 */
class WebhookQueueDrainer implements EventSubscriberInterface {

  const QUEUE = 'webhook_entities_processor';

  const LOCK = 'as_webhook_entities.drain';

  /**
   * Seconds to keep claiming items in one request.
   */
  const DRAIN_BUDGET = 40;

  /**
   * Seconds to wait before the first claim, so a burst lands in one batch.
   */
  const SETTLE = 2;

  /**
   * Lease on a claimed item, after which another drainer may take it.
   */
  const LEASE = 120;

  /**
   * Whether this request asked for a drain.
   */
  protected bool $requested = FALSE;

  /**
   * Whether to wait SETTLE seconds before claiming.
   */
  protected bool $settle = TRUE;

  public function __construct(
    protected QueueFactory $queueFactory,
    protected QueueWorkerManagerInterface $workerManager,
    protected LockBackendInterface $lock,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected ConfigFactoryInterface $configFactory,
    protected KeyRepositoryInterface $keyRepository,
    protected ClientInterface $httpClient,
    protected RequestStack $requestStack,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
    protected WebhookUuidLookup $uuidLookup,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Ahead of KernelDestructionSubscriber (100), so services that flush on
    // destruction (cache tags, path aliases) still see this drain's saves.
    return [KernelEvents::TERMINATE => ['onTerminate', 200]];
  }

  /**
   * Whether received notifications should be processed straight away.
   *
   * Reuses the crontrigger setting: sites that ran cron on every notification
   * now get a batched drain instead. Off means the queue waits for cron.
   */
  public function isEnabled(): bool {
    return (bool) $this->configFactory->get('as_webhook_entities.settings')->get('crontrigger');
  }

  /**
   * Asks for a drain once this request's response has been sent.
   *
   * @param bool $settle
   *   FALSE for a follow-up drain, where the backlog is already waiting and
   *   pausing to gather a burst only adds delay.
   */
  public function request(bool $settle = TRUE): void {
    $this->requested = TRUE;
    $this->settle = $this->settle && $settle;
  }

  /**
   * Runs a requested drain.
   */
  public function onTerminate(): void {
    if ($this->requested) {
      $this->requested = FALSE;
      $this->drain();
    }
  }

  /**
   * Processes the queue until it is empty or the time budget runs out.
   *
   * @return int
   *   The number of notifications processed.
   */
  public function drain(int $budget = self::DRAIN_BUDGET): int {
    $queue = $this->queueFactory->get(self::QUEUE);
    if (!$queue->numberOfItems()) {
      return 0;
    }
    // Another request is draining and will reach this item.
    if (!$this->lock->acquire(self::LOCK, $budget + self::LEASE)) {
      return 0;
    }

    $deadline = $this->time->getCurrentTime() + $budget;
    $worker = $this->workerManager->createInstance(self::QUEUE);
    $touched = [];
    $processed = 0;
    try {
      if ($this->settle) {
        sleep(self::SETTLE);
      }
      while ($this->time->getCurrentTime() < $deadline && ($item = $queue->claimItem(self::LEASE))) {
        try {
          $worker->processItem($item->data);
          $queue->deleteItem($item);
          $processed++;
          $this->noteTouched($item->data, $touched);
        }
        catch (DelayedRequeueException $e) {
          if ($queue instanceof DelayableQueueInterface) {
            $queue->delayItem($item, $e->getDelay());
          }
        }
        catch (RequeueException $e) {
          $queue->releaseItem($item);
        }
        catch (SuspendQueueException $e) {
          $queue->releaseItem($item);
          $this->logger->warning('Webhook drain suspended: @message', ['@message' => $e->getMessage()]);
          break;
        }
        catch (\Throwable $e) {
          // Left claimed, as cron does: it is retried once the lease expires.
          $this->logger->error('Webhook drain failed on an item: @message', ['@message' => $e->getMessage()]);
        }
      }
      $this->indexTouched($touched);
    }
    finally {
      $this->lock->release(self::LOCK);
    }

    $left = $queue->numberOfItems();
    $this->logger->info('Webhook drain processed @n notification(s); @left left in the queue.', [
      '@n' => $processed,
      '@left' => $left,
    ]);
    if ($left) {
      $this->continueElsewhere();
    }
    return $processed;
  }

  /**
   * Records the node a processed notification created or updated.
   */
  protected function noteTouched($payload, array &$touched): void {
    $data = is_string($payload) ? json_decode($payload) : NULL;
    if (empty($data->uuid) || empty($data->type) || ($data->event ?? '') === 'delete' || $data->type === 'term') {
      return;
    }
    $entity = $this->uuidLookup->findEntity($data->uuid, $data->type);
    if ($entity && $entity->getEntityTypeId() === 'node') {
      $touched[$entity->id()] = $entity->language()->getId();
    }
  }

  /**
   * Indexes this batch's nodes now rather than at the next cron run.
   *
   * Only items the index is already tracking as changed are indexed, so bundle
   * filters and datasource settings are respected and nothing else in a
   * backlog is pulled forward.
   */
  protected function indexTouched(array $touched): void {
    if (!$touched || !$this->moduleHandler->moduleExists('search_api')) {
      return;
    }
    $ids = [];
    foreach ($touched as $nid => $langcode) {
      $ids[] = 'entity:node/' . $nid . ':' . $langcode;
    }
    $indexes = $this->entityTypeManager->getStorage('search_api_index')->loadByProperties(['status' => TRUE]);
    foreach ($indexes as $index) {
      if ($index->isReadOnly() || !$index->isValidDatasource('entity:node')) {
        continue;
      }
      try {
        $pending = array_intersect($ids, $index->getTrackerInstance()->getRemainingItems());
        if ($pending) {
          $items = $index->loadItemsMultiple($pending);
          if ($items) {
            $index->indexSpecificItems($items);
          }
        }
      }
      catch (\Throwable $e) {
        // Cron indexes them later; a search hiccup must not fail the drain.
        $this->logger->warning('Webhook drain could not index @index: @message', [
          '@index' => $index->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Starts a fresh drain in another request for whatever is still queued.
   */
  protected function continueElsewhere(): void {
    $key = $this->keyRepository->getKey('as_webhook_entities_token');
    $token = $key ? $key->getKeyValue() : NULL;
    $request = $this->requestStack->getCurrentRequest();
    if (!$token || !$request) {
      return;
    }
    try {
      // An empty body queues nothing; it only asks for a drain. The listener
      // answers before draining, so this returns in well under the timeout.
      $this->httpClient->request('POST', $request->getSchemeAndHttpHost() . '/webhook-entities/listener', [
        'headers' => ['Authorization' => $token],
        'body' => '',
        'timeout' => 10,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->warning('Webhook drain could not start a follow-up drain; cron will pick the rest up: @message', ['@message' => $e->getMessage()]);
    }
  }

}
