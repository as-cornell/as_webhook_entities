<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\as_webhook_entities\ParagraphTreeBuilder;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Handles `page` and `landing_page` payloads from artsci-as.
 *
 * Both bundles share their field mapping and their whole paragraph tree, so one
 * class serves both; the bundle is injected and the handler is registered twice
 * in WebhookCrudManager.
 *
 * This handler covers **ongoing sync only**. The one-time bulk move of the 279
 * page / landing_page nodes runs through the Migrate API instead, so that it
 * gets map tables, progress reporting and, above all, `migrate:rollback`:
 * unwinding 279 nodes and ~1,700 paragraphs by hand is not a recovery plan.
 *
 * Both paths share ParagraphTreeBuilder rather than each rebuilding the tree
 * their own way. Two implementations would drift, and that drift would show up
 * as content which imported correctly once and then changed shape on its first
 * update.
 *
 * @see \Drupal\as_webhook_entities\ParagraphTreeBuilder
 * @see \Drupal\as_webhook_entities\WebhookHandler\WebhookHandlerBase
 */
class PageWebhookHandler extends WebhookHandlerBase {

  /**
   * Allowed-value translation for field_sidebar_type -> field_page_layout.
   *
   * Artsci-as offers left/right/none; departments offers
   * hasSidebar/fullPage/sidebarNoMenu. There is no one-to-one match, so both
   * sidebar positions collapse to "has sidebar": departments decides the side
   * by theme rather than by field.
   */
  protected const LAYOUT_MAP = [
    'left' => 'hasSidebar',
    'right' => 'hasSidebar',
    'none' => 'fullPage',
  ];

  /**
   * Node fields handled explicitly, so the catch-all loop skips them.
   */
  protected const HANDLED_FIELDS = [
    'field_summary',
    'field_paragraph',
    'field_sidebar_type',
    'field_pano',
    'field_pano_two',
    'body',
  ];

  /**
   * The node bundle this instance handles.
   */
  protected string $bundle;

  /**
   * The shared paragraph tree builder.
   */
  protected ParagraphTreeBuilder $treeBuilder;

  /**
   * Constructs a PageWebhookHandler.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\as_webhook_entities\ParagraphTreeBuilder $treeBuilder
   *   The shared paragraph tree builder.
   * @param string $bundle
   *   The node bundle to handle, 'page' or 'landing_page'.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ParagraphTreeBuilder $treeBuilder, string $bundle = 'page') {
    parent::__construct($entityTypeManager);
    $this->treeBuilder = $treeBuilder;
    $this->bundle = $bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return $this->bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function applyCreateFields(array &$node_values, object $entity_data, array $domain_schema): void {
    $this->treeBuilder->resetIssues();
    $node_values['type'] = $this->bundle;

    // field_remote_uuid is the idempotency key for sync. Without it a repeat
    // notification creates a second node instead of updating the first.
    if (!empty($entity_data->uuid)) {
      $node_values['field_remote_uuid'] = $entity_data->uuid;
    }

    // Without the domain fields the node appears on no domain at all.
    $domains = $this->resolveDomainsFromDepartments($entity_data);
    $node_values['field_domain_access'] = $domains;
    $node_values['field_domain_source'] = reset($domains) ?: NULL;

    $this->applyScalarFields($node_values, $entity_data);
    $this->applyPanoField($node_values, $entity_data, $domains);

    $components = $this->treeBuilder->buildTree(
      (array) ($entity_data->components ?? []), $domains);
    if ($components) {
      $node_values['field_page_components'] = $components;
    }

    $this->logIssues($entity_data);
  }

  /**
   * {@inheritdoc}
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void {
    $this->treeBuilder->resetIssues();

    $domains = $this->resolveDomainsFromDepartments($entity_data);
    $existing_entity->set('field_domain_access', $domains);

    $values = [];
    $this->applyScalarFields($values, $entity_data);
    $this->applyPanoField($values, $entity_data, $domains);
    foreach ($values as $name => $value) {
      if ($existing_entity->hasField($name)) {
        $existing_entity->set($name, $value);
      }
    }

    // Replace the component tree wholesale. Diffing a nested paragraph tree is
    // not worth the risk of half-updating it, and the source is authoritative.
    if ($existing_entity->hasField('field_page_components')) {
      $existing_entity->set('field_page_components', $this->treeBuilder->buildTree(
        (array) ($entity_data->components ?? []), $domains));
    }

    $this->logIssues($entity_data);
  }

  /**
   * {@inheritdoc}
   */
  public function getChangedTime(object $entity_data): ?int {
    return !empty($entity_data->changed) ? (int) $entity_data->changed : NULL;
  }

  /**
   * Maps the node-level fields, applying renames and value translation.
   *
   * @param array $node_values
   *   Node values, by reference.
   * @param object $entity_data
   *   The payload.
   */
  protected function applyScalarFields(array &$node_values, object $entity_data): void {
    $fields = (array) ($entity_data->fields ?? []);

    // field_page_summary is required on both target bundles. When the source
    // summary is empty, fall back to the opening body paragraph rather than
    // failing validation, exactly as the article handler does.
    $summary = $this->firstScalar($fields['field_summary'] ?? NULL);
    if ($summary === NULL || trim((string) $summary) === '') {
      $summary = $this->deriveSummaryFromBody($this->firstScalar($fields['body'] ?? NULL));
    }
    if ($summary !== NULL && trim((string) $summary) !== '') {
      $node_values['field_page_summary'] = $summary;
    }

    // Sidebar position becomes a layout choice.
    $sidebar = $this->firstScalar($fields['field_sidebar_type'] ?? NULL);
    if ($sidebar !== NULL) {
      if (isset(static::LAYOUT_MAP[$sidebar])) {
        $node_values['field_page_layout'] = static::LAYOUT_MAP[$sidebar];
      }
      else {
        $node_values['field_page_layout'] = 'fullPage';
        $this->issue(sprintf('unmapped field_sidebar_type "%s", defaulted to fullPage', $sidebar));
      }
    }

    if (!empty($fields['body'])) {
      $node_values['body'] = $this->treeBuilder->convertValue($fields['body'], 'node.body', []);
    }

    // Anything the source has that departments also has under the same name.
    foreach ($fields as $name => $value) {
      if (in_array($name, static::HANDLED_FIELDS, TRUE)) {
        continue;
      }
      $node_values[$name] = $this->treeBuilder->convertValue($value, 'node.' . $name, []);
    }
  }

  /**
   * Converts the source pano image into the target's shape.
   *
   * Artsci-as stores landing_page pano as an entity_reference to an image
   * media. departments stores it as an entity_reference_revisions to a `pano`
   * paragraph, which itself holds field_image. Same field name, different
   * storage, so the image has to be wrapped rather than copied.
   *
   * `page` has no pano paragraph field at all, only a plain field_pano_image,
   * so it takes the first image directly.
   *
   * `field_pano_two` has no target equivalent. On landing_page the second image
   * becomes another pano paragraph so it survives; on page it is logged lost.
   *
   * @param array $node_values
   *   Node values, by reference.
   * @param object $entity_data
   *   The payload.
   * @param array $domains
   *   Domains to tag imported media with.
   */
  protected function applyPanoField(array &$node_values, object $entity_data, array $domains): void {
    $fields = (array) ($entity_data->fields ?? []);
    $mids = [];

    foreach (['field_pano', 'field_pano_two'] as $source_field) {
      foreach ((array) ($fields[$source_field] ?? []) as $reference) {
        $mid = $this->treeBuilder->importMedia($reference, $source_field, $domains);
        if ($mid) {
          $mids[] = $mid;
        }
      }
    }

    if (!$mids) {
      return;
    }

    if ($this->bundle === 'landing_page') {
      $panos = [];
      foreach ($mids as $mid) {
        $paragraph = Paragraph::create([
          'type' => 'pano',
          'field_image' => ['target_id' => $mid],
        ]);
        $paragraph->save();
        $panos[] = $paragraph;
      }
      $node_values['field_pano'] = $panos;
      return;
    }

    $node_values['field_pano_image'] = ['target_id' => reset($mids)];
    if (count($mids) > 1) {
      $this->issue(sprintf('%d extra pano image(s) dropped: page has one field_pano_image',
        count($mids) - 1));
    }
  }

  /**
   * Records a handler-level issue alongside the tree builder's own.
   *
   * @param string $message
   *   The issue description.
   */
  protected function issue(string $message): void {
    // Routed through the builder so one flush covers both sources.
    $this->treeBuilder->addIssue($message);
  }

  /**
   * Returns the first scalar out of a serialized field value.
   *
   * @param mixed $value
   *   A serialized field value.
   *
   * @return mixed
   *   The first scalar, or NULL.
   */
  protected function firstScalar($value) {
    if ($value === NULL) {
      return NULL;
    }
    $items = (array) $value;
    $first = reset($items);
    if (is_array($first)) {
      return $first['value'] ?? NULL;
    }
    return $first;
  }

  /**
   * Logs everything that could not be carried, as one grouped warning.
   *
   * Grouped deliberately: one line per node keeps a 279-node import readable,
   * where one line per issue would not be.
   *
   * @param object $entity_data
   *   The payload, for identifying the node in the log.
   */
  protected function logIssues(object $entity_data): void {
    $issues = $this->treeBuilder->takeIssues();
    if (!$issues) {
      return;
    }
    \Drupal::logger('as_webhook_entities')->warning(
      'Page sync for @title (@uuid): @count item(s) not carried: @list',
      [
        '@title' => $entity_data->title ?? '?',
        '@uuid' => substr((string) ($entity_data->uuid ?? ''), 0, 12),
        '@count' => count($issues),
        '@list' => implode('; ', $issues),
      ]
    );
  }

}
