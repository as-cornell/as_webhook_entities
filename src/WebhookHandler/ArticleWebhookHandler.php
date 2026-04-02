<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

/**
 * Webhook handler for the 'article' entity type.
 *
 * Maps webhook payload fields to Drupal article node fields for both
 * create and update operations, including related people and article
 * lookups via remote UUID.
 */
class ArticleWebhookHandler extends WebhookHandlerBase {

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
    $node_values['field_bylines'] = $entity_data->field_bylines ?? NULL;
    $node_values['field_dateline'] = $entity_data->field_dateline ?? NULL;
    $node_values['field_media_sources'] = $entity_data->field_media_sources ?? NULL;
    $node_values['field_external_media_source'] = $entity_data->field_external_media_source ?? NULL;
    $node_values['field_portrait_image_path'] = $entity_data->field_portrait_image_path ?? NULL;
    $node_values['field_portrait_image_alt'] = $entity_data->field_portrait_image_alt ?? NULL;
    $node_values['field_landscape_image_path'] = $entity_data->field_landscape_image_path ?? NULL;
    $node_values['field_landscape_image_alt'] = $entity_data->field_landscape_image_alt ?? NULL;
    $node_values['field_thumbnail_image_path'] = $entity_data->field_thumbnail_image_path ?? NULL;
    $node_values['field_thumbnail_image_alt'] = $entity_data->field_thumbnail_image_alt ?? NULL;
    $node_values['field_page_summary'] = $entity_data->field_page_summary ?? NULL;

    if (!empty($entity_data->field_body)) {
      $node_values['field_body'] = ['value' => $entity_data->field_body->value, 'format' => $entity_data->field_body->format];
    }

    // Only set these fields if they exist on the destination bundle.
    $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
    if (isset($field_definitions['field_card_label'])) {
      $node_values['field_card_label'] = $entity_data->field_card_label ?? NULL;
    }
    if (isset($field_definitions['field_pano_image_path'])) {
      $node_values['field_pano_image_path'] = $entity_data->field_pano_image_path ?? NULL;
      $node_values['field_pano_image_alt'] = $entity_data->field_pano_image_alt ?? NULL;
    }
    if (isset($field_definitions['field_related_disciplines'])) {
      $tids = $this->lookupTermTidsByName((array) ($entity_data->field_related_disciplines ?? []));
      if (!empty($tids)) {
        $node_values['field_related_disciplines'] = $tids;
      }
    }
    if (isset($field_definitions['field_summary'])) {
      $node_values['field_summary'] = $entity_data->field_summary ?? NULL;
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
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void {
    $existing_entity->field_page_summary->value = $entity_data->field_page_summary ?? NULL;
    $existing_entity->field_portrait_image_alt->value = $entity_data->field_portrait_image_alt ?? NULL;
    $existing_entity->field_landscape_image_path->value = $entity_data->field_landscape_image_path ?? NULL;
    $existing_entity->field_landscape_image_alt->value = $entity_data->field_landscape_image_alt ?? NULL;
    $existing_entity->field_thumbnail_image_path->value = $entity_data->field_thumbnail_image_path ?? NULL;
    $existing_entity->field_thumbnail_image_alt->value = $entity_data->field_thumbnail_image_alt ?? NULL;
    $existing_entity->field_body->value = $entity_data->field_body?->value ?? NULL;
    $existing_entity->field_body->format = $entity_data->field_body?->format ?? NULL;
    $existing_entity->field_bylines->value = $entity_data->field_bylines ?? NULL;
    $existing_entity->field_dateline->value = $entity_data->field_dateline ?? NULL;
    $existing_entity->field_media_sources->value = $entity_data->field_media_sources ?? NULL;
    $existing_entity->field_external_media_source->value = $entity_data->field_external_media_source ?? NULL;
    if ($existing_entity->hasField('field_card_label')) {
      $existing_entity->field_card_label->value = $entity_data->field_card_label ?? NULL;
    }
    if ($existing_entity->hasField('field_pano_image_path')) {
      $existing_entity->field_pano_image_path->value = $entity_data->field_pano_image_path ?? NULL;
      $existing_entity->field_pano_image_alt->value = $entity_data->field_pano_image_alt ?? NULL;
    }
    if ($existing_entity->hasField('field_related_disciplines')) {
      $tids = $this->lookupTermTidsByName((array) ($entity_data->field_related_disciplines ?? []));
      $existing_entity->set('field_related_disciplines', !empty($tids) ? $tids : []);
    }
    if ($existing_entity->hasField('field_summary')) {
      $existing_entity->field_summary->value = $entity_data->field_summary ?? NULL;
    }
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
