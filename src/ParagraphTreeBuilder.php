<?php

namespace Drupal\as_webhook_entities;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Rebuilds a serialized paragraph tree as real paragraph entities.
 *
 * Shared deliberately by two callers so the logic exists once:
 *   - the bulk migration of page / landing_page from artsci-as, via a Migrate
 *     API process plugin, which gets map tables and `migrate:rollback` from
 *     Migrate rather than from here;
 *   - PageWebhookHandler, for ongoing sync after the bulk move.
 *
 * If bulk and sync each had their own rebuilder they would drift, and the
 * drift would show up as content that imported correctly once and then changed
 * shape on its first update.
 *
 * The input is produced by artsci-as `scripts/serialize-page-paragraphs.php`,
 * which serializes references as *portable* identifiers because IDs differ
 * between the two sites:
 *   - `_ref: taxonomy_term` carries name + source vid, resolved here by name.
 *     This is what lets the vocabulary renames survive
 *     (discipline -> academic_interests,
 *     majors_minors_gradfields -> departments_programs).
 *   - `_ref: node` carries a UUID, resolved against field_remote_uuid.
 *   - `_ref: media` carries a UUID and URL, imported by WebhookImageImporter.
 *   - a nested paragraph carries `_bundle` and is recursed into.
 *
 * Nothing is dropped silently. Every unresolved item is recorded and readable
 * via getIssues(), because the source data carries known accepted losses
 * (dangling article references, and four paragraph rows deleted outright from
 * artsci-as) and those must stay visible after import rather than looking like
 * clean content.
 */
class ParagraphTreeBuilder {

  /**
   * Source-only paragraph fields with no target field, deliberately dropped.
   *
   * These hold real data on artsci-as (site-wide: field_block_title 28 rows,
   * field_list_label 22, field_selected_stat_nodes 4) but departments has no
   * equivalent field. Listing them makes the drop a decision rather than an
   * accident, and each occurrence is recorded.
   */
  protected const DROPPED_FIELDS = [
    'field_block_title',
    'field_list_label',
    'field_selected_stat_nodes',
  ];

  /**
   * Paragraph bundles that must never arrive.
   *
   * The webform paragraphs are converted to formatted_text_block on the source
   * side by artsci-as `scripts/scrape-nexus-webform-content.php`, because
   * departments has neither type and the decision was to carry the content
   * without carrying webforms forward. If one arrives, that scrape did not run.
   */
  protected const REJECTED_BUNDLES = [
    'person_webform_wrapper',
    'webform_submission',
  ];

  /**
   * Hard recursion ceiling, matching the serializer's own limit.
   *
   * Real content only reaches depth 2, so this is purely a cycle guard.
   */
  protected const MAX_DEPTH = 12;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The image importer service.
   */
  protected WebhookImageImporter $imageImporter;

  /**
   * Items that could not be carried, collected for the caller to report.
   *
   * @var string[]
   */
  protected array $issues = [];

  /**
   * Cached paragraph type ids available on this site.
   *
   * @var string[]|null
   */
  protected ?array $paragraphTypes = NULL;

  /**
   * Constructs a ParagraphTreeBuilder.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\as_webhook_entities\WebhookImageImporter $image_importer
   *   The image importer service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, WebhookImageImporter $image_importer) {
    $this->entityTypeManager = $entity_type_manager;
    $this->imageImporter = $image_importer;
  }

  /**
   * Builds every top-level paragraph for one node.
   *
   * @param array $components
   *   The serialized components, keyed by the source field name. The key is
   *   ignored: every tree lands in field_page_components on this side.
   * @param array $domains
   *   Domains to tag any imported media with.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   *   Saved paragraphs, in source order.
   */
  public function buildTree(array $components, array $domains): array {
    $built = [];
    foreach ($components as $items) {
      foreach ((array) $items as $item) {
        $paragraph = $this->buildParagraph((array) $item, $domains, 1);
        if ($paragraph) {
          $built[] = $paragraph;
        }
      }
    }
    return $built;
  }

  /**
   * Recursively builds one paragraph and everything beneath it.
   *
   * @param array $data
   *   A serialized paragraph, carrying at least `_bundle`.
   * @param array $domains
   *   Domains for imported media.
   * @param int $depth
   *   Current depth, 1 for a paragraph sitting directly on the node.
   *
   * @return \Drupal\paragraphs\ParagraphInterface|null
   *   The saved paragraph, or NULL when it could not be built.
   */
  public function buildParagraph(array $data, array $domains, int $depth = 1): ?ParagraphInterface {
    $bundle = $data['_bundle'] ?? NULL;
    if (!$bundle) {
      $this->issues[] = 'paragraph payload with no _bundle at depth ' . $depth;
      return NULL;
    }

    if ($depth > static::MAX_DEPTH) {
      $this->issues[] = sprintf('"%s" exceeded max depth %d', $bundle, static::MAX_DEPTH);
      return NULL;
    }

    if (in_array($bundle, static::REJECTED_BUNDLES, TRUE)) {
      $this->issues[] = sprintf(
        'refused bundle "%s": webform content must be converted on the source side first',
        $bundle);
      return NULL;
    }

    // A paragraph deleted outright from artsci-as arrives marked truncated.
    if (!empty($data['_truncated'])) {
      $this->issues[] = sprintf('truncated paragraph "%s" at depth %d', $bundle, $depth);
      return NULL;
    }

    if (!$this->paragraphTypeExists($bundle)) {
      $this->issues[] = sprintf('paragraph type "%s" does not exist here', $bundle);
      return NULL;
    }

    $values = ['type' => $bundle];
    foreach ($data as $name => $value) {
      if (strpos($name, 'field_') !== 0) {
        continue;
      }
      if (in_array($name, static::DROPPED_FIELDS, TRUE)) {
        $this->issues[] = sprintf('dropped %s.%s (no target field)', $bundle, $name);
        continue;
      }
      $converted = $this->convertValue($value, $bundle . '.' . $name, $domains, $depth);
      if ($converted !== NULL && $converted !== []) {
        $values[$name] = $converted;
      }
    }

    $paragraph = Paragraph::create($values);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Converts one serialized field value into a Drupal field value.
   *
   * @param mixed $value
   *   The serialized value, normally a list of item arrays.
   * @param string $context
   *   Owner for diagnostics, e.g. "faq_wrapper.field_faqs".
   * @param array $domains
   *   Domains for imported media.
   * @param int $depth
   *   Current depth.
   *
   * @return mixed
   *   A value suitable for Entity::set(), or NULL.
   */
  public function convertValue($value, string $context, array $domains, int $depth = 0) {
    if ($value === NULL) {
      return NULL;
    }
    $items = is_array($value) ? $value : [$value];
    $out = [];

    foreach ($items as $item) {
      // Scalars pass straight through.
      if (!is_array($item)) {
        $out[] = $item;
        continue;
      }

      // A nested paragraph. This covers both the source's
      // entity_reference_revisions fields and the two it stores as a plain
      // entity_reference to a paragraph (faq_wrapper.field_faqs,
      // embed_figure_wrapper.field_select_embed_figures), which departments
      // stores as revisions. Building the child here supplies the revision id
      // that a straight value copy could not.
      if (isset($item['_bundle'])) {
        $child = $this->buildParagraph($item, $domains, $depth + 1);
        if ($child) {
          $out[] = $child;
        }
        continue;
      }

      if (isset($item['_ref'])) {
        $resolved = $this->resolveReference($item, $context, $domains);
        if ($resolved !== NULL) {
          $out[] = $resolved;
        }
        continue;
      }

      // Rich text: run the body through the image importer so absolute <img>
      // tags become local <drupal-media> embeds pointing at imported files.
      // Without this, images inside paragraph text stay hot-linked to
      // artsci-as and break the moment that site is retired or its DNS moves.
      if (isset($item['value'], $item['format']) && is_string($item['value'])) {
        $item['value'] = $this->imageImporter->processBodyHtml($item['value'], $domains);
      }

      // Structured scalars: text, link, and anything already shaped as a field
      // item array.
      $out[] = array_filter($item, fn($v) => $v !== NULL);
    }

    return $out;
  }

  /**
   * Resolves a portable reference to a local field value.
   *
   * @param array $item
   *   The reference, carrying `_ref`.
   * @param string $context
   *   Owner for diagnostics.
   * @param array $domains
   *   Domains for imported media.
   *
   * @return array|null
   *   A field item value, or NULL when unresolvable.
   */
  public function resolveReference(array $item, string $context, array $domains): ?array {
    switch ($item['_ref']) {
      case 'taxonomy_term':
        // By name, never by tid or vid: the vocabularies are renamed between
        // the two sites, so only the human label is portable.
        $tid = $this->lookupTermByName((string) ($item['name'] ?? ''));
        if ($tid === NULL) {
          $this->issues[] = sprintf('unresolved term "%s" (source vocab %s) for %s',
            $item['name'] ?? '?', $item['vid'] ?? '?', $context);
          return NULL;
        }
        return ['target_id' => $tid];

      case 'node':
        $nid = $this->lookupNodeByRemoteUuid((string) ($item['uuid'] ?? ''));
        if ($nid !== NULL) {
          return ['target_id' => $nid];
        }
        // Persons are sourced from artsci-people, so a person deleted on
        // artsci-as may still exist here under the same name. Recover the link
        // where possible. Where the person has genuinely left the institution
        // there is nothing to link to, and the caller keeps the plain label.
        if (($item['bundle'] ?? '') === 'person' && !empty($item['title'])) {
          $nid = $this->lookupNodeByTitle((string) $item['title'], 'person');
          if ($nid !== NULL) {
            return ['target_id' => $nid];
          }
        }
        $this->issues[] = sprintf('unresolved %s reference "%s" (uuid %s) for %s',
          $item['bundle'] ?? 'node', $item['title'] ?? '?',
          substr((string) ($item['uuid'] ?? ''), 0, 12), $context);
        return NULL;

      case 'media':
        $mid = $this->importMedia($item, $context, $domains);
        return $mid ? ['target_id' => $mid] : NULL;

      default:
        $this->issues[] = sprintf('unhandled reference type "%s" for %s', $item['_ref'], $context);
        return NULL;
    }
  }

  /**
   * Imports the media for a serialized media reference, returning its id.
   *
   * No dedup logic here on purpose. WebhookImageImporter already keys each
   * stored file on a digest of the source URL, so a repeated URL reuses one
   * file while two sources that merely share a basename stay separate. It also
   * rewrites style derivatives back to the original file. Reimplementing any of
   * that here would only risk diverging from it.
   *
   * @param array|object $reference
   *   The serialized media reference.
   * @param string $context
   *   Owner for diagnostics.
   * @param array $domains
   *   Domains to tag newly imported media with.
   *
   * @return int|null
   *   The media entity id, or NULL.
   */
  public function importMedia($reference, string $context, array $domains): ?int {
    $reference = (array) $reference;
    $url = $reference['url'] ?? NULL;
    if (!$url) {
      $this->issues[] = sprintf('media reference with no url for %s', $context);
      return NULL;
    }

    try {
      $mid = $this->imageImporter->importImage(
        $url,
        (string) ($reference['alt'] ?? $reference['name'] ?? ''),
        $domains
      );
    }
    catch (\Throwable $e) {
      $this->issues[] = sprintf('media import failed for %s (%s): %s', $context, $url, $e->getMessage());
      return NULL;
    }

    if ($mid === NULL) {
      $this->issues[] = sprintf('media import returned nothing for %s (%s)', $context, $url);
    }
    return $mid;
  }

  /**
   * Records an issue raised by a caller, so one flush covers both sources.
   *
   * @param string $message
   *   The issue description.
   */
  public function addIssue(string $message): void {
    $this->issues[] = $message;
  }

  /**
   * Returns and clears the collected issues.
   *
   * @return string[]
   *   The issues recorded since the last reset.
   */
  public function takeIssues(): array {
    $issues = $this->issues;
    $this->issues = [];
    return $issues;
  }

  /**
   * Clears collected issues without reading them.
   */
  public function resetIssues(): void {
    $this->issues = [];
  }

  /**
   * Looks up a single term id by name, across any vocabulary.
   *
   * Name-based on purpose: see the class docblock. Duplicated in spirit from
   * WebhookHandlerBase, which cannot be reused here because this is a service
   * rather than a handler, and widening that base class's API for one consumer
   * would be the worse trade.
   *
   * @param string $name
   *   The term name.
   *
   * @return int|null
   *   The term id, or NULL when there is no match.
   */
  protected function lookupTermByName(string $name): ?int {
    $name = trim($name);
    if ($name === '') {
      return NULL;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['name' => $name]);
    if (!$terms) {
      return NULL;
    }
    $term = reset($terms);
    return (int) $term->id();
  }

  /**
   * Looks up a node id by its artsci-as source UUID.
   *
   * @param string $uuid
   *   The source node UUID.
   *
   * @return int|null
   *   The node id, or NULL when nothing matches.
   */
  protected function lookupNodeByRemoteUuid(string $uuid): ?int {
    if (trim($uuid) === '') {
      return NULL;
    }
    $nodes = $this->entityTypeManager->getStorage('node')
      ->loadByProperties(['field_remote_uuid' => $uuid]);
    if (!$nodes) {
      return NULL;
    }
    $node = reset($nodes);
    return (int) $node->id();
  }

  /**
   * Looks up a node id by exact title within a bundle.
   *
   * @param string $title
   *   The title to match.
   * @param string $bundle
   *   The node bundle.
   *
   * @return int|null
   *   The node id, or NULL when there is no unambiguous single match.
   */
  protected function lookupNodeByTitle(string $title, string $bundle): ?int {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('title', $title)
      ->range(0, 2)
      ->execute();
    // Only accept an unambiguous match: two people can share a name, and
    // guessing would attach the wrong profile to somebody's work.
    return count($ids) === 1 ? (int) reset($ids) : NULL;
  }

  /**
   * Whether a paragraph type exists on this site.
   *
   * @param string $bundle
   *   The paragraph type id.
   *
   * @return bool
   *   TRUE when it exists.
   */
  protected function paragraphTypeExists(string $bundle): bool {
    if ($this->paragraphTypes === NULL) {
      $this->paragraphTypes = array_keys(
        $this->entityTypeManager->getStorage('paragraphs_type')->loadMultiple()
      );
    }
    return in_array($bundle, $this->paragraphTypes, TRUE);
  }

}
