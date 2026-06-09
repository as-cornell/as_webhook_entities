<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

use Drupal\as_webhook_entities\WebhookImageImporter;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Webhook handler for the 'article' entity type.
 *
 * Maps webhook payload fields to Drupal article node fields for both
 * create and update operations, including related people and article
 * lookups via remote UUID.
 */
class ArticleWebhookHandler extends WebhookHandlerBase {

  /**
   * The webhook image importer service.
   *
   * @var \Drupal\as_webhook_entities\WebhookImageImporter
   */
  protected WebhookImageImporter $imageImporter;

  /**
   * Constructs an ArticleWebhookHandler object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\as_webhook_entities\WebhookImageImporter $imageImporter
   *   The webhook image importer service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, WebhookImageImporter $imageImporter) {
    parent::__construct($entityTypeManager);
    $this->imageImporter = $imageImporter;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'article';
  }

  /**
   * {@inheritdoc}
   */
  public function applyCreateFields(array &$node_values, object $entity_data, array $domain_schema): void {
    $node_values['type'] = 'article';
    $node_values['field_remote_uuid'] = $entity_data->uuid;

    // Set created timestamp from dateline date field, falling back to the
    // source node's created timestamp.
    if (!empty($entity_data->field_dateline) && ($ts = strtotime($entity_data->field_dateline)) !== FALSE && $ts > 0) {
      $node_values['created'] = $ts;
    }
    elseif (!empty($entity_data->created)) {
      $node_values['created'] = (int) $entity_data->created;
    }

    $node_values['field_bylines'] = $entity_data->field_bylines ?? NULL;
    $node_values['field_dateline'] = $entity_data->field_dateline ?? NULL;
    $node_values['field_external_media_source'] = $entity_data->field_external_media_source ?? NULL;
    if (!empty($entity_data->field_media_sources)) {
      $node_values['field_media_source_reference'] = $this->lookupOrCreateTermByName($entity_data->field_media_sources, 'article_mediasources');
    }
    $node_values['field_card_label'] = $entity_data->field_card_label ?? NULL;
    $node_values['field_summary'] = $entity_data->field_summary ?? NULL;
    $node_values['field_portrait_image_path'] = $entity_data->field_portrait_image_path ?? NULL;
    $node_values['field_portrait_image_alt'] = $entity_data->field_portrait_image_alt ?? NULL;
    $node_values['field_landscape_image_path'] = $entity_data->field_landscape_image_path ?? NULL;
    $node_values['field_landscape_image_alt'] = $entity_data->field_landscape_image_alt ?? NULL;
    $node_values['field_thumbnail_image_path'] = $entity_data->field_thumbnail_image_path ?? NULL;
    $node_values['field_thumbnail_image_alt'] = $entity_data->field_thumbnail_image_alt ?? NULL;
    $node_values['field_pano_image_path'] = $entity_data->field_pano_image_path ?? NULL;
    $node_values['field_pano_image_alt'] = $entity_data->field_pano_image_alt ?? NULL;

    // Import remote images as managed media entities, tagging each with the
    // article's domain_access values so they are visible on the correct domain.
    $domains = ($domain_schema['schema'] ?? '') === 'departments'
      ? $this->resolveDomainsFromDepartments($entity_data)
      : [];
    $image_map = [
      'field_portrait_image_path'  => ['field_image', 'field_portrait_image_alt'],
      'field_landscape_image_path' => ['field_landscape_image', 'field_landscape_image_alt'],
      'field_thumbnail_image_path' => ['field_thumbnail_image', 'field_thumbnail_image_alt'],
      'field_pano_image_path'      => ['field_pano_image', 'field_pano_image_alt'],
    ];
    foreach ($image_map as $path_field => [$media_field, $alt_field]) {
      if (!empty($entity_data->$path_field)) {
        $mid = $this->imageImporter->importImage($entity_data->$path_field, $entity_data->$alt_field ?? '', $domains);
        if ($mid) {
          $node_values[$media_field] = $mid;
        }
      }
    }

    if (!empty($entity_data->field_body)) {
      $node_values['field_body'] = [
        'value'  => $this->imageImporter->processBodyHtml($entity_data->field_body->value, $domains),
        'format' => $entity_data->field_body->format,
      ];
    }

    // Related disciplines lookup by term name within the discipline vocabulary.
    $tids = $this->lookupTermTidsByNameInVocab((array) ($entity_data->field_related_disciplines ?? []), 'discipline');
    if (!empty($tids)) {
      $node_values['field_related_disciplines'] = $tids;
    }

    // Tags lookup by term name within the tags vocabulary.
    $tids = $this->lookupTermTidsByNameInVocab((array) ($entity_data->field_tags ?? []), 'tags');
    if (!empty($tids)) {
      $node_values['field_tags'] = $tids;
    }

    // Related people lookup by remote UUID.
    $nids = $this->lookupNodeNidsByRemoteUuid((array) ($entity_data->field_related_people ?? []));
    if (!empty($nids)) {
      $node_values['field_related_people'] = $nids;
    }

    // Related articles lookup by remote UUID.
    $nids = $this->lookupNodeNidsByRemoteUuid((array) ($entity_data->field_related_articles ?? []));
    if (!empty($nids)) {
      $node_values['field_related_articles'] = $nids;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Returns the dateline timestamp so the created node sorts by publication
   * date rather than import date.
   */
  public function getChangedTime(object $entity_data): ?int {
    if (!empty($entity_data->field_dateline)) {
      $ts = strtotime($entity_data->field_dateline);
      if ($ts !== FALSE && $ts > 0) {
        return $ts;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void {
    $existing_entity->set('field_portrait_image_path', $entity_data->field_portrait_image_path ?? NULL);
    $existing_entity->set('field_portrait_image_alt', $entity_data->field_portrait_image_alt ?? NULL);
    $existing_entity->set('field_landscape_image_path', $entity_data->field_landscape_image_path ?? NULL);
    $existing_entity->set('field_landscape_image_alt', $entity_data->field_landscape_image_alt ?? NULL);
    $existing_entity->set('field_thumbnail_image_path', $entity_data->field_thumbnail_image_path ?? NULL);
    $existing_entity->set('field_thumbnail_image_alt', $entity_data->field_thumbnail_image_alt ?? NULL);
    $existing_entity->set('field_pano_image_path', $entity_data->field_pano_image_path ?? NULL);
    $existing_entity->set('field_pano_image_alt', $entity_data->field_pano_image_alt ?? NULL);

    // Import remote images as managed media entities, tagging each with the
    // article's domain_access values so they are visible on the correct domain.
    $domains = ($domain_schema['schema'] ?? '') === 'departments'
      ? $this->resolveDomainsFromDepartments($entity_data)
      : [];
    $image_map = [
      'field_portrait_image_path'  => ['field_image', 'field_portrait_image_alt'],
      'field_landscape_image_path' => ['field_landscape_image', 'field_landscape_image_alt'],
      'field_thumbnail_image_path' => ['field_thumbnail_image', 'field_thumbnail_image_alt'],
      'field_pano_image_path'      => ['field_pano_image', 'field_pano_image_alt'],
    ];
    foreach ($image_map as $path_field => [$media_field, $alt_field]) {
      if (!empty($entity_data->$path_field)) {
        $mid = $this->imageImporter->importImage($entity_data->$path_field, $entity_data->$alt_field ?? '', $domains);
        if ($mid) {
          $existing_entity->set($media_field, $mid);
        }
      }
    }

    $existing_entity->set('field_body', [
      'value'  => $this->imageImporter->processBodyHtml($entity_data->field_body?->value ?? '', $domains),
      'format' => $entity_data->field_body?->format ?? NULL,
    ]);
    $existing_entity->set('field_bylines', $entity_data->field_bylines ?? NULL);
    $existing_entity->set('field_dateline', $entity_data->field_dateline ?? NULL);
    $existing_entity->set('field_external_media_source', $entity_data->field_external_media_source ?? NULL);
    if (!empty($entity_data->field_media_sources)) {
      $existing_entity->set('field_media_source_reference', $this->lookupOrCreateTermByName($entity_data->field_media_sources, 'article_mediasources'));
    }
    else {
      $existing_entity->set('field_media_source_reference', NULL);
    }
    $existing_entity->set('field_card_label', $entity_data->field_card_label ?? NULL);
    $existing_entity->set('field_summary', $entity_data->field_summary ?? NULL);

    // Related disciplines lookup by term name within the discipline vocabulary.
    $tids = $this->lookupTermTidsByNameInVocab((array) ($entity_data->field_related_disciplines ?? []), 'discipline');
    $existing_entity->set('field_related_disciplines', !empty($tids) ? $tids : []);

    // Tags lookup by term name within the tags vocabulary.
    $tids = $this->lookupTermTidsByNameInVocab((array) ($entity_data->field_tags ?? []), 'tags');
    $existing_entity->set('field_tags', !empty($tids) ? $tids : []);

    // Related people.
    $peoplearray = !empty($entity_data->field_related_people)
      ? $this->lookupNodeNidsByRemoteUuid((array) $entity_data->field_related_people)
      : [];
    $existing_entity->set('field_related_people', $peoplearray);

    // Related articles.
    $articlesarray = !empty($entity_data->field_related_articles)
      ? $this->lookupNodeNidsByRemoteUuid((array) $entity_data->field_related_articles)
      : [];
    $existing_entity->set('field_related_articles', $articlesarray);
  }

}
