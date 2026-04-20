<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

/**
 * Webhook handler for the 'media_report_entry' entity type.
 *
 * Maps webhook payload fields to Drupal media_report_entry node fields for
 * both create and update operations. Handles department/program taxonomy
 * lookups, related people references, and comma-separated link fields.
 *
 * Note: Fixes two bugs present in the original monolithic handler:
 *   - field_news_date was incorrectly assigned from field_outlet_name.
 *   - field_media_report_public_cat had a typo ($fentity_data->ield_...).
 */
class MediaReportEntryWebhookHandler extends WebhookHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'media_report_entry';
  }

  /**
   * {@inheritdoc}
   */
  public function applyCreateFields(array &$node_values, object $entity_data, array $domain_schema): void {
    $node_values['type'] = 'media_report_entry';
    $node_values['field_remote_uuid'] = $entity_data->uuid;
    $node_values['field_outlet_name'] = $entity_data->field_outlet_name ?? NULL;
    $node_values['field_news_date'] = $entity_data->field_news_date ?? NULL;
    $node_values['field_media_report_public_cat'] = $entity_data->field_media_report_public_cat ?? NULL;
    $node_values['body'] = $entity_data->body ?? NULL;
    $node_values['summary'] = $entity_data->summary ?? NULL;

    // field_related_department_program from department names.
    $tids = $this->lookupTermTidsByName((array) ($entity_data->field_departments_programs ?? []));
    if (!empty($tids)) {
      $node_values['field_related_department_program'] = $tids;
    }

    // field_related_people.
    $nids = $this->lookupNodeNidsByRemoteUuid((array) ($entity_data->field_related_people ?? []));
    if (!empty($nids)) {
      $node_values['field_related_people'] = $nids;
    }

    // field_news_link from comma-separated URIs.
    if (!empty($entity_data->field_link)) {
      $links = explode(',', $entity_data->field_link);
      $node_values['field_news_link'] = array_map(
        fn($uri, $key) => ['uri' => $uri, 'title' => 'Article'],
        $links,
        array_keys($links)
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void {
    $existing_entity->set('field_outlet_name', $entity_data->field_outlet_name ?? NULL);
    $existing_entity->set('field_news_date', $entity_data->field_news_date ?? NULL);
    $existing_entity->set('field_media_report_public_cat', $entity_data->field_media_report_public_cat ?? NULL);
    $existing_entity->set('body', ['summary' => $entity_data->summary ?? NULL, 'value' => $entity_data->body ?? NULL, 'format' => 'plain_text']);
    $existing_entity->set('field_news_link', !empty($entity_data->field_news_link)
      ? array_map(fn($uri) => ['uri' => $uri, 'title' => 'Article'], explode(',', $entity_data->field_news_link))
      : NULL);
    // Related people.
    $nids = $this->lookupNodeNidsByRemoteUuid((array) ($entity_data->field_related_people ?? []));
    $existing_entity->set('field_related_people', !empty($nids) ? $nids : []);
  }

}
