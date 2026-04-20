<?php

namespace Drupal\as_webhook_entities\WebhookHandler;

use Drupal\paragraphs\Entity\Paragraph;

/**
 * Webhook handler for the 'person' entity type.
 *
 * Maps webhook payload fields to Drupal person node fields for both
 * create and update operations, including paragraph creation for
 * overview/research data and domain-specific taxonomy lookups.
 */
class PersonWebhookHandler extends WebhookHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return 'person';
  }

  /**
   * {@inheritdoc}
   */
  public function applyCreateFields(array &$node_values, object $entity_data, array $domain_schema): void {
    $node_values['type'] = 'person';
    $node_values['field_remote_uuid'] = $entity_data->uuid;
    $node_values['field_netid'] = $entity_data->netid ?? NULL;
    $node_values['field_person_last_name'] = $entity_data->field_person_last_name ?? NULL;
    $node_values['field_job_title'] = $entity_data->field_job_title ?? NULL;
    $node_values['field_portrait_image_path'] = $entity_data->field_portrait_image_path ?? NULL;
    $node_values['field_summary'] = $entity_data->field_summary ?? NULL;
    $node_values['field_primary_college'] = $entity_data->field_primary_college ?? NULL;
    $node_values['field_affiliated_colleges'] = $entity_data->field_affiliated_colleges ?? [];
    $node_values['field_exclude_directory'] = (bool) ($entity_data->field_exclude_directory ?? FALSE);
    $node_values['field_hide_contact_info'] = (bool) ($entity_data->field_hide_contact_info ?? FALSE);

    if (!empty($entity_data->field_body)) {
      $node_values['field_body'] = ['value' => $entity_data->field_body->value, 'format' => $entity_data->field_body->format];
    }
    if (!empty($entity_data->field_education)) {
      $node_values['field_education'] = ['value' => $entity_data->field_education->value, 'format' => $entity_data->field_education->format];
    }
    if (!empty($entity_data->field_keywords)) {
      $node_values['field_keywords'] = ['value' => $entity_data->field_keywords->value, 'format' => $entity_data->field_keywords->format];
    }

    // field_person_type taxonomy lookup.
    $tids = $this->lookupTermTidsByName([$entity_data->field_person_type ?? '']);
    if (!empty($tids)) {
      $node_values['field_person_type'] = $tids;
    }

    // field_primary_department taxonomy lookup.
    if (!empty($entity_data->field_primary_department)) {
      $tids = $this->lookupTermTidsByName([$entity_data->field_primary_department]);
      if (!empty($tids)) {
        $node_values['field_primary_department'] = $tids;
      }
    }

    // field_link from links array.
    if (!empty($entity_data->field_links)) {
      $node_values['field_link'] = array_map(
        fn($l) => ['uri' => $l->uri, 'title' => $l->title],
        $entity_data->field_links
      );
    }

    // Domain-specific fields for 'departments' and 'as' schemas.
    if (in_array($domain_schema['schema'] ?? '', ['departments', 'as'])) {
      $raarray = $this->lookupTermTidsByProperty('field_people_tid', (array) ($entity_data->field_research_areas ?? []));
      if (!empty($raarray)) {
        $node_values['field_research_areas'] = $raarray;
      }

      $aiarray = $this->lookupTermTidsByProperty('field_people_tid', (array) ($entity_data->field_academic_interests ?? []));
      if (!empty($aiarray)) {
        $node_values['field_academic_interests'] = $aiarray;
      }
    }

    // Domain-specific fields for 'departments' schema only.
    if (($domain_schema['schema'] ?? '') === 'departments') {
      $ararray = $this->lookupTermTidsByProperty('field_people_tid', (array) ($entity_data->field_academic_role ?? []));
      if (!empty($ararray)) {
        $node_values['field_academic_role'] = $ararray;
      }
    }

    // field_overview_research paragraphs.
    if (!empty($entity_data->field_overview_research)) {
      $paragraphs = [];
      foreach ($entity_data->field_overview_research as $orr) {
        $ordeptarray = $this->lookupTermTidsByName((array) ($orr->departments_programs ?? []));
        $paragraph = Paragraph::create([
          'type' => 'overview_research',
          'field_departments_programs' => $ordeptarray,
          'field_description' => ['value' => $orr->overview, 'format' => $orr->format],
          'field_person_research_focus' => ['value' => $orr->research, 'format' => $orr->format],
        ]);
        $paragraph->save();
        $paragraphs[] = $paragraph;
      }
      $node_values['field_overview_research'] = $paragraphs;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function applyUpdateFields(object $existing_entity, object $entity_data, array $domain_schema): void {
    $existing_entity->set('field_netid', $entity_data->netid ?? NULL);
    $existing_entity->set('field_person_last_name', $entity_data->field_person_last_name ?? NULL);
    $existing_entity->set('field_summary', $entity_data->field_summary ?? NULL);
    $existing_entity->set('field_education', ['value' => $entity_data->field_education?->value ?? NULL, 'format' => $entity_data->field_education?->format ?? NULL]);
    $existing_entity->set('field_keywords', ['value' => $entity_data->field_keywords?->value ?? NULL, 'format' => $entity_data->field_keywords?->format ?? NULL]);
    $existing_entity->set('field_body', ['value' => $entity_data->field_body?->value ?? NULL, 'format' => $entity_data->field_body?->format ?? NULL]);
    $existing_entity->set('field_primary_college', $entity_data->field_primary_college ?? NULL);
    $existing_entity->set('field_affiliated_colleges', $entity_data->field_affiliated_colleges ?? []);
    $existing_entity->set('field_job_title', $entity_data->field_job_title ?: NULL);
    $existing_entity->set('field_exclude_directory', (bool) ($entity_data->field_exclude_directory ?? FALSE));
    $existing_entity->set('field_hide_contact_info', (bool) ($entity_data->field_hide_contact_info ?? FALSE));

    // field_primary_department lookup.
    if (!empty($entity_data->field_primary_department)) {
      $tids = $this->lookupTermTidsByName([$entity_data->field_primary_department]);
      if (!empty($tids)) {
        $existing_entity->set('field_primary_department', $tids);
      }
    }

    // field_person_type taxonomy lookup.
    if (!empty($entity_data->field_person_type)) {
      $tids = $this->lookupTermTidsByName([$entity_data->field_person_type]);
      if (!empty($tids)) {
        $existing_entity->set('field_person_type', $tids);
      }
    }

    // Domain: 'departments' only — academic_role.
    if (($domain_schema['schema'] ?? '') === 'departments') {
      $ararray = $this->lookupTermTidsByProperty('field_people_tid', (array) ($entity_data->field_academic_role ?? []));
      $existing_entity->set('field_academic_role', !empty($ararray) ? $ararray : NULL);
    }

    // Domain: 'departments' or 'as' — research_areas, academic_interests, overview_research, field_link.
    if (in_array($domain_schema['schema'] ?? '', ['departments', 'as'])) {
      $raarray = $this->lookupTermTidsByProperty('field_people_tid', (array) ($entity_data->field_research_areas ?? []));
      $existing_entity->set('field_research_areas', !empty($raarray) ? $raarray : NULL);

      $aiarray = $this->lookupTermTidsByProperty('field_people_tid', (array) ($entity_data->field_academic_interests ?? []));
      $existing_entity->set('field_academic_interests', !empty($aiarray) ? $aiarray : NULL);

      // Delete existing overview_research paragraphs and recreate.
      $paragraph_field = 'field_overview_research';
      if (!$existing_entity->get($paragraph_field)->isEmpty()) {
        $paragraph_ids = array_column($existing_entity->get($paragraph_field)->getValue(), 'target_id');
        if (!empty($paragraph_ids)) {
          $paragraph_storage = \Drupal::entityTypeManager()->getStorage('paragraph');
          $paragraph_storage->delete($paragraph_storage->loadMultiple($paragraph_ids));
        }
        $existing_entity->get($paragraph_field)->setValue([]);
      }
      if (!empty($entity_data->field_overview_research)) {
        foreach ($entity_data->field_overview_research as $orr) {
          $ordeptarray = $this->lookupTermTidsByName((array) ($orr->departments_programs ?? []));
          $paragraph = Paragraph::create([
            'type' => 'overview_research',
            'field_departments_programs' => $ordeptarray,
            'field_description' => ['value' => $orr->overview, 'format' => $orr->format],
            'field_person_research_focus' => ['value' => $orr->research, 'format' => $orr->format],
          ]);
          $paragraph->save();
          $existing_entity->get($paragraph_field)->appendItem($paragraph);
        }
      }

      // field_link.
      $existing_entity->set('field_link', !empty($entity_data->field_links)
        ? array_map(fn($l) => ['uri' => $l->uri, 'title' => $l->title], $entity_data->field_links)
        : NULL);
    }
  }

}
