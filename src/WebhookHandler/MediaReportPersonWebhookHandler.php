<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

/**
 * Webhook handler for the 'media_report_person' entity type.
 *
 * Creates/updates person nodes sourced from the media report system.
 * Maps a minimal set of person fields and handles comma-separated link URIs.
 */
class MediaReportPersonWebhookHandler extends WebhookHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'media_report_person';
  }

  /**
   * {@inheritdoc}
   */
  public function applyCreateFields(array &$node_values, object $entity_data, array $domain_schema): void {
    $node_values['type'] = 'person';
    $node_values['field_remote_uuid'] = $entity_data->uuid;
    $node_values['field_people_uuid'] = $entity_data->uuid;
    $node_values['field_person_last_name'] = $entity_data->field_person_last_name ?? NULL;
    $node_values['field_netid'] = $entity_data->netid ?? NULL;

    // field_person_type taxonomy lookup.
    $tids = $this->lookupTermTidsByName([$entity_data->field_person_type ?? '']);
    if (!empty($tids)) {
      $node_values['field_person_type'] = $tids;
    }

    // field_link from comma-separated URIs.
    if (!empty($entity_data->field_link)) {
      $node_values['field_link'] = array_map(
        fn($uri) => ['uri' => $uri, 'title' => 'Person Record'],
        explode(',', $entity_data->field_link)
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void {
    $existing_entity->set('field_person_last_name', $entity_data->field_person_last_name ?? NULL);
    $existing_entity->set('field_netid', $entity_data->netid ?? NULL);
    $existing_entity->set('field_people_uuid', $entity_data->uuid ?? NULL);
    $existing_entity->set('field_remote_uuid', $entity_data->uuid ?? NULL);

    // field_person_type taxonomy lookup.
    if (!empty($entity_data->field_person_type)) {
      $tids = $this->lookupTermTidsByName([$entity_data->field_person_type]);
      if (!empty($tids)) {
        $existing_entity->set('field_person_type', $tids);
      }
    }

    $existing_entity->set('field_link', !empty($entity_data->field_link)
      ? array_map(fn($uri) => ['uri' => $uri, 'title' => 'Person Record'], explode(',', $entity_data->field_link))
      : NULL);
  }

}
