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
   * Thresholds for deriveSummaryFromBody().
   *
   * A character floor alone lets junk through: a bare registration URL clears
   * 60 characters on the strength of one token, and so does a one-line
   * subtitle. Requiring a sentence's worth of words filters both.
   */
  protected const MIN_SUMMARY_WORDS = 12;
  protected const MAX_SUMMARY_TOKEN = 60;
  protected const MAX_SUMMARY_LENGTH = 600;

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
   * {@inheritdoc}
   */
  public function getChangedTime(object $entity_data): ?int {
    return NULL;
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
   * Looks up taxonomy term tids by name within a specific vocabulary.
   *
   * @param array $names
   *   An array of taxonomy term name strings.
   * @param string $vid
   *   The vocabulary machine name to restrict the lookup to.
   *
   * @return array
   *   An array of term IDs (tids) for matching terms.
   */
  protected function lookupTermTidsByNameInVocab(array $names, string $vid): array {
    if (empty($names)) {
      return [];
    }
    $tids = [];
    foreach ($names as $name) {
      if (empty($name)) {
        continue;
      }
      $results = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties(['name' => $name, 'vid' => $vid]);
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
   * Looks up a taxonomy term by name in a given vocabulary, creating it if
   * it does not already exist. Returns the term ID.
   *
   * @param string $name
   *   The term name to find or create.
   * @param string $vid
   *   The vocabulary machine name.
   *
   * @return int
   *   The term ID.
   */
  protected function lookupOrCreateTermByName(string $name, string $vid): int {
    $name = trim($name);
    $results = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['name' => $name, 'vid' => $vid]);
    if (!empty($results)) {
      return (int) reset($results)->id();
    }
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->create([
      'name' => $name,
      'vid'  => $vid,
    ]);
    $term->save();
    return (int) $term->id();
  }

  /**
   * Resolves domain_access target IDs from a payload's field_departments_programs.
   *
   * Looks up each department name in the departments_programs vocabulary and
   * reads field_domain_access_target_id from the matched term. Always prepends
   * 'departments_as_cornell_edu', even when the payload has no department data.
   *
   * @param object $entity_data
   *   The decoded webhook payload entity data object.
   *
   * @return array
   *   An array of domain target ID strings.
   */
  protected function resolveDomainsFromDepartments(object $entity_data): array {
    $domains = [];
    foreach ((array) ($entity_data->field_departments_programs ?? []) as $dpname) {
      $results = $this->entityTypeManager->getStorage('taxonomy_term')
        ->loadByProperties(['name' => $dpname, 'vid' => 'departments_programs']);
      if ($dp = reset($results)) {
        $domain = $dp->get('field_domain_access_target_id')->value;
        if ($domain) {
          $domains[] = $domain;
        }
      }
    }
    array_unshift($domains, 'departments_as_cornell_edu', 'as_cornell_edu');
    return array_unique($domains);
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

  /**
   * Decodes HTML entities in a value bound for a plain-text field.
   *
   * The source systems send some values HTML-encoded, so a department called
   * "Astronomy & Space Sciences" arrives as "Astronomy &amp; Space Sciences".
   * Writing that straight into a plain-text field stores the entity itself,
   * and Twig then escapes the ampersand a second time on output, so the page
   * displays the literal text "&amp;".
   *
   * ONLY use this for plain-text fields - string, string_long, list_string.
   * Formatted-text fields (those carrying a 'format' alongside their 'value')
   * are rendered as HTML, so entities in them are correct and decoding would
   * corrupt the markup. On the person handler that means field_body,
   * field_education and field_keywords must be left alone.
   *
   * Decoding is deliberately applied once rather than repeatedly. The stored
   * values are singly encoded, and looping would eventually mangle text that
   * legitimately contains an escaped ampersand.
   *
   * @param mixed $value
   *   The incoming value. Non-strings are returned untouched so this can be
   *   applied without having to check for NULL at every call site.
   *
   * @return mixed
   *   The decoded string, or the original value when it is not a string.
   */
  protected function decodePlainText(mixed $value): mixed {
    if (!is_string($value) || $value === '') {
      return $value;
    }
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  /**
   * Derives a plain-text summary from the first usable paragraph of body HTML.
   *
   * The upstream feed frequently sends articles with no summary - roughly two
   * thirds of the archive arrived that way - and the teaser templates fall back
   * to the summary field, so those articles render an empty summary line.
   * Rather than leave the gap, take the opening paragraph of the body.
   *
   * "Usable" means at least MIN_SUMMARY_WORDS words with no single token longer
   * than MAX_SUMMARY_TOKEN. Bodies often open with a bare registration URL or a
   * one-line subtitle, which makes a worse summary than none; those are stepped
   * over and the next paragraph is tried, so the article still gets a summary.
   *
   * @param string|null $html
   *   Body markup from the payload, or NULL.
   *
   * @return string|null
   *   Plain-text summary, or NULL when nothing usable was found.
   */
  protected function deriveSummaryFromBody(?string $html): ?string {
    if ($html === NULL || trim($html) === '') {
      return NULL;
    }

    $candidates = [];
    if (preg_match_all('#<p[^>]*>(.*?)</p>#is', $html, $matches)) {
      $candidates = $matches[1];
    }
    // Bodies without <p> wrappers still have text worth using.
    $candidates[] = $html;

    foreach ($candidates as $candidate) {
      $text = html_entity_decode(strip_tags($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      // Non-breaking spaces survive decoding and would skew the word count.
      $text = str_replace("\xC2\xA0", ' ', $text);
      $text = trim(preg_replace('/\s+/', ' ', $text));

      $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
      if (count($words) < self::MIN_SUMMARY_WORDS) {
        continue;
      }
      // A paragraph carried by one long URL is not a summary.
      $longest = 0;
      foreach ($words as $word) {
        $longest = max($longest, mb_strlen($word));
      }
      if ($longest > self::MAX_SUMMARY_TOKEN) {
        continue;
      }

      if (mb_strlen($text) > self::MAX_SUMMARY_LENGTH) {
        $cut = mb_substr($text, 0, self::MAX_SUMMARY_LENGTH);
        $space = mb_strrpos($cut, ' ');
        $text = ($space ? mb_substr($cut, 0, $space) : $cut) . '…';
      }

      return $text;
    }

    return NULL;
  }

}
