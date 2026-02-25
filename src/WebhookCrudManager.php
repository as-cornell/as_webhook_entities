<?php

namespace Drupal\as_webhook_entities;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\taxonomy\Entity\Term;

use Psr\Log\LoggerInterface;

use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 *
 * Entity CRUD operations in response to webhook notifications.
 *
 */
class WebhookCrudManager {

  /**
   * The manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The default logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;
  

  /**
   * Constructs a WebhookCrudManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerInterface $logger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
  }

  
  /**
   * Creates a new entity using notification data.
   *
   * @param object $entity_data
   *   Required data from the notification body.
   */
  public function createEntity($entity_data) {
    // Map incoming notification values to Drupal fields.
    $node_values = $this->mapFieldData($entity_data);
    $domain_schema = $this->getDomainSchema();
    $host = \Drupal::request()->getHost();
    // Ensure any required values exist before proceeding.
    // UUID was already checked in the queue worker.
     
    if (!empty($node_values['title'])) {
      // Add other values used for node creation.
      // Update publication status.
      if ($entity_data->status == '1') {
        $node_values['status'] = TRUE;
      }
      if ($entity_data->status == '0') {
        $node_values['status'] = FALSE;
      }
      // Update author.
      $node_values['uid'] = $entity_data->uid;
      
    // if node type is person
    if ($entity_data->type == 'person') {
      $node_values['type'] = 'person';
      $node_values['field_remote_uuid'] = $entity_data->uuid;
      $node_values['field_netid'] = $entity_data->netid;
      $node_values['field_person_last_name'] = $entity_data->field_person_last_name;
      $node_values['field_job_title'] = $entity_data->field_job_title;
      $node_values['field_portrait_image_path'] = $entity_data->field_portrait_image_path;
      // Look up field_person_type by name, never null
      $ptnames = $entity_data->field_person_type;
        $ptlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $ptname]);
        if ($pt = reset($ptlookup)) {
          $ptarray[] = $pt->get('tid')->value;
        }
      if (!empty($ptarray)) {
        $node_values['field_person_type'] = $ptarray;
      }
      // Look up field_primary_department tid by department name.
      if (!empty($entity_data->field_primary_department)) {
        $pdname = $entity_data->field_primary_department;
        $pdlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $pdname]);
        if ($pd = reset($pdlookup)) {
          $pdarray[] = $pd->get('tid')->value;
        }
      }
      if (!empty($pdarray)) {
        $node_values['field_primary_department'] = $pdarray;
      }
      // primary college
      if (!empty($entity_data->field_primary_college)) {
        $node_values['field_primary_college'] = $entity_data->field_primary_college;
      }
      // affiliated colleges
      if (!empty($entity_data->field_affiliated_colleges)) {
        $acarray = $entity_data->field_affiliated_colleges;
        if (!empty($acarray)) {
          $node_values['field_affiliated_colleges'] = $acarray;
        }
      }
      // update field_exclude_directory
      if ($entity_data->field_exclude_directory == '1') {
        $node_values['field_exclude_directory'] = TRUE;
      }
      if ($entity_data->field_exclude_directory == '0') {
        $node_values['field_exclude_directory'] = FALSE;
      }
      // update field_hide_contact_info
      if ($entity_data->field_hide_contact_info == '1') {
        $node_values['field_hide_contact_info'] = TRUE;
      }
      if ($entity_data->field_hide_contact_info == '0') {
        $node_values['field_hide_contact_info'] = FALSE;
      }
      // only on depts and as
      if ($domain_schema['schema'] == 'departments' ||  $domain_schema['schema'] == 'as') {
        // Look up field_research_areas by people tid.
        $rauuids = $entity_data->field_research_areas;
        foreach ($rauuids as $rauuid) {
          $ralookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $rauuid]);
          if ($ra = reset($ralookup)) {
            $raarray[] = $ra->get('tid')->value;
          }
        }
        if (!empty($raarray)) {
          $node_values['field_research_areas'] = $raarray;
        }
        // Look up field_academic_role by people tid.
        $aruuids = $entity_data->field_academic_role;
        foreach ($aruuids as $aruuid) {
          $arlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $aruuid]);
          if ($ar = reset($arlookup)) {
            $ararray[] = $ar->get('tid')->value;
          }
        }
        if (!empty($ararray)) {
          $node_values['field_academic_role'] = $ararray;
        }
        // Look upfield_academic_interests by people tid.
        $aiuuids = $entity_data->field_academic_interests;
        foreach ($aiuuids as $aiuuid) {
          $ailookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $aiuuid]);
          if ($ai = reset($ailookup)) {
            $aiarray[] = $ai->get('tid')->value;
          }
        }
        if (!empty($aiarray)) {
          $node_values['field_academic_interests'] = $aiarray;
        }
      //end just on departments and as
      }
      // only on depts
      if ($domain_schema['schema'] == 'departments' ) {
        // Look up field_academic_role by people tid.
        $aruuids = $entity_data->field_academic_role;
        foreach ($aruuids as $aruuid) {
          $arlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $aruuid]);
          if ($ar = reset($arlookup)) {
            $ararray[] = $ar->get('tid')->value;
          }
        }
        if (!empty($ararray)) {
          $node_values['field_academic_role'] = $ararray;
        }
      //end just on departments
      }
      
      $node_values['field_summary'] = $entity_data->field_summary;
      
      //field_overview_research
      if (!empty($entity_data->field_overview_research)) {
        // make paragraphs
        foreach ($entity_data->field_overview_research as $orr) {
          $ordeptarray = [];
          // get array of tids from names
          foreach ($orr->departments_programs as $dept) {
            $ordeptlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dept]);
            if ($ordpl = reset($ordeptlookup)) {
              $ordeptarray[] = $ordpl->get('tid')->value;
            }
          }
          // Create a new paragraph
          $paragraph = Paragraph::create([
            'type' => 'overview_research',
            'field_departments_programs' => $ordeptarray,
            'field_description' => array(
              'value'=>$orr->overview,
              'format'=>$orr->format
              ),
            'field_person_research_focus' => array(
              'value'=>$orr->research,
              'format'=>$orr->format
              ),
          ]);
          $paragraph->save();
          $paragraphs[] = $paragraph;
        }
        $node_values['field_overview_research'] = $paragraphs;
      }

      $node_values['field_body']['value'] = $entity_data->field_body->value;
      $node_values['field_body']['format'] = $entity_data->field_body->format;
      $node_values['field_education']['value'] = $entity_data->field_education->value;
      $node_values['field_education']['format'] = $entity_data->field_education->format;
      $node_values['field_keywords']['value'] = $entity_data->field_keywords->value;
      $node_values['field_keywords']['format'] = $entity_data->field_keywords->format;
    // set field_link from merged array
    if (!empty($entity_data->field_links)) {
      $linkarray = [];
      foreach ($entity_data->field_links as $link) {
        $linkarray[] = array('uri'=>$link->uri,'title'=>$link->title);
      }
    }
    if (!empty($linkarray)) {
      $node_values['field_link'] = $linkarray;
    }
  }
      
    // if node type is article
    if ($entity_data->type == 'article') {
      $node_values['type'] = $entity_data->type;
      $node_values['title'] = $entity_data->title;
      $node_values['field_remote_uuid'] = $entity_data->uuid;
      $node_values['field_bylines'] = $entity_data->field_bylines;
      $node_values['field_dateline'] = $entity_data->field_dateline;
      $node_values['field_media_sources'] = $entity_data->field_media_sources;
      $node_values['field_external_media_source'] = $entity_data->field_external_media_source;
      $node_values['field_portrait_image_path'] = $entity_data->field_portrait_image_path;
      $node_values['field_portrait_image_alt'] = $entity_data->field_portrait_image_alt;
      $node_values['field_landscape_image_path'] = $entity_data->field_landscape_image_path;
      $node_values['field_landscape_image_alt'] = $entity_data->field_landscape_image_alt;
      $node_values['field_thumbnail_image_path'] = $entity_data->field_thumbnail_image_path;
      $node_values['field_thumbnail_image_alt'] = $entity_data->field_thumbnail_image_alt;
      $node_values['field_page_summary'] = $entity_data->field_page_summary;
      $node_values['field_body']['value'] = $entity_data->field_body->value;
      $node_values['field_body']['format'] = $entity_data->field_body->format;

      
      // look up nids for array of related people using remote uuid
      $peopleuuids = $entity_data->field_related_people;
      foreach ($peopleuuids as $personuuid) {
        $personlookup = $this->entityTypeManager->getStorage('node')->loadByProperties(['field_remote_uuid' => $personuuid]);
        if ($person = reset($personlookup)) {
          $peoplearray[] = $person->get('nid')->value;
        }
      }
      if (!empty($peoplearray)) {
        $node_values['field_related_people'] =  $peoplearray;
      }
      
      // look up nids for array of related articles using remote uuid
      $articleuuids = $entity_data->field_related_articles;
      foreach ($articleuuids as $articleuuid) {
        $articlelookup = $this->entityTypeManager->getStorage('node')->loadByProperties(['field_remote_uuid' => $articleuuid]);
        if ($article = reset($articlelookup)) {
          $articlesarray[] = $article->get('nid')->value;
        }
      }
      if (!empty($articlesarray)) {
        $node_values['field_related_articles'] = $articlesarray;
      }
      // end article
      }


  // fields specific to media_report_entry content type
  if ($entity_data->type == 'media_report_entry') {
    $node_values['type'] = $entity_data->type;
    $node_values['field_remote_uuid'] = $entity_data->uuid;
    $node_values['field_outlet_name'] = $entity_data->field_outlet_name;
    $node_values['field_news_date'] = $entity_data->field_outlet_name;
    $node_values['field_media_report_public_cat'] = $fentity_data->ield_media_report_public_cat;
    $node_values['body'] = $entity_data->body;
    $node_values['summary'] = $entity_data->summary;
    // field_related_department_program
    $dpnames = $entity_data->field_departments_programs;
    foreach ($dpnames as $dpname) {
      $dplookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dpname]);
        if ($dp = reset($dplookup)) {
          $dparray[] = $dp->get('tid')->value;
        }
    }
    if (!empty($dparray)) {
      $node_values['field_related_department_program'] = $dparray;
    }
   // look up nids for array of related people using remote uuid
    $peopleuuids = $entity_data->field_related_people;
    foreach ($peopleuuids as $personuuid) {
      $personlookup = $this->entityTypeManager->getStorage('node')->loadByProperties(['field_remote_uuid' => $personuuid]);
      if ($person = reset($personlookup)) {
        $peoplearray[] = $person->get('nid')->value;
      }
    }
    if (!empty($peoplearray)) {
      $node_values['field_related_people'] =  $peoplearray;
    }
    // set field_link from merged array
    if (!empty($entity_data->field_link)) {
      $links = explode(',', $entity_data->field_link);
      foreach ($links as $key =>$link) {
        $linkarray[$key]['uri'] = $link;
        $linkarray[$key]['title'] = 'Article';
      }
      $node_values['field_news_link'] = $linkarray;
    }
  
  // end media_report_entry
  }

  // fields specific to person content type on media report
  if ($entity_data->type == 'media_report_person') {
    $node_values['type'] = 'person';
    $node_values['field_remote_uuid'] = $entity_data->uuid;
    $node_values['field_people_uuid'] = $entity_data->uuid;
    $node_values['field_person_last_name'] = $entity_data->field_person_last_name;
    $node_values['field_netid'] = $entity_data->netid;
    // Look up field_person_type by name, never null
    $ptnames = $entity_data->field_person_type;
      $ptlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $ptname]);
      if ($pt = reset($ptlookup)) {
        $ptarray[] = $pt->get('tid')->value;
      }
    if (!empty($ptarray)) {
      $node_values['field_person_type'] = $ptarray;
    }
    // set field_link from merged array
    if (!empty($entity_data->field_link)) {
      $links = explode(',', $entity_data->field_link);
      foreach ($links as $key =>$link) {
        $linkarray[$key]['uri'] = $link;
        $linkarray[$key]['title'] = 'Person Record';
      }
      $node_values['field_link'] = $linkarray;
    }
  
  // end media_report_person
  }

  // Set field_departments_programs and simultaneously map domain access on departments
  $dpnames = $entity_data->field_departments_programs;
  foreach ($dpnames as $dpname) {
    $dplookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dpname]);
    if ($dp = reset($dplookup)) {
      $dparray[] = $dp->get('tid')->value;
    // only on depts
    if ($domain_schema['schema'] == 'departments' ) {
      $daarray[] = $dp->get('field_domain_access_target_id')->value;
      }
    }
  }
  if (!empty($dparray)) {
    $node_values['field_departments_programs'] = $dparray;
  }
  // only on depts
  if ($domain_schema['schema'] == 'departments' ) {
    if (!empty($daarray)) {
      // add departments_as_cornell_edu by default
      array_push($daarray, 'departments_as_cornell_edu');
      $node_values['field_domain_access'] = $daarray;
    }
  }
  // Attempt to create a node from the notification data.
      try {
        $storage = $this->entityTypeManager->getStorage('node');
        $node = $storage->create($node_values);
        $node->save();
        // Log a message when sucessful
        $this->logger->notice('Node @nid created to represent webhook entity @uuid', [
          '@nid' => $node->id(),
          '@uuid' => $entity_data->uuid
        ]);
      }
      // Display an error if the node could not be created.
      catch (\Exception $e) {
        $this->logger->warning('A node could not be created to represent webhook entity @uuid. @error', [
          '@uuid' => $entity_data->uuid,
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Creates a new entity using notification data.
   *
   * @param object $entity_data
   *   Required data from the notification body.
   */
  public function createTermEntity($entity_data) {
    // start fresh
    $node_values = [];
    $domain_schema = $this->getDomainSchema();
    // Ensure any required values exist before proceeding.
    // UUID was already checked in the queue worker.
    if (!empty($entity_data->title)) {
      $node_values['name'] = $entity_data->title;
      $node_values['vid'] = $entity_data->vocabulary;
      // update field_people_tid
      if (!empty($entity_data->field_people_tid)) {
        $node_values['field_people_tid'] = $entity_data->field_people_tid;
      }
      // look up parent using field_people_tid of parent
      if (!empty($entity_data->parent)) {
        $parentlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $entity_data->parent]);
          if ($parent = reset($parentlookup)) {
            $node_values['parent'] = $parent->get('tid')->value;
        }
      }
      if ($domain_schema['schema'] == 'departments' ) {
        // if on departments, use field_departments_programs to map domain access to department names
        $dpnames = $entity_data->field_departments_programs;
        foreach ($dpnames as $dpname) {
          $dplookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dpname]);
          if ($dp = reset($dplookup)) {
            $daarray[] = $dp->get('field_domain_access_target_id')->value;
          }
        }
        if (!empty($daarray)) {
          array_push($daarray, 'departments_as_cornell_edu');
          $node_values['domain_access'] = $daarray;
        }
      }
      
      // Attempt to create a term from the notification data.
      try {
        $storage = $this->entityTypeManager->getStorage('taxonomy_term');
        $entity = $storage->create($node_values);
        $entity->save();
        // Log a message when sucessful
        $this->logger->notice('Entity @id created to represent webhook entity @type @uuid', [
          '@id' => $entity->id(),
          '@uuid' => $entity_data->uuid,
          '@type' => $entity_data->type,
        ]);
      }
      // Display an error if the entity could not be created.
      catch (\Exception $e) {
        $this->logger->warning('An entity could not be created to represent webhook entity @uuid. @error', [
          '@uuid' => $entity_data->uuid,
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

/**
 * Updates an existing entity with notification data.
 *
 * @param object $existing_entity
 *   A Drupal entity that was loaded by its UUID field.
 * @param object $entity_data
 *   Required data from the notification body.
 */
public function updateEntity($existing_entity, $entity_data) {
  // Flag to track update status.
  $updated = FALSE;
  // host for conditions based on current domain
  $host = \Drupal::request()->getHost();
  $domain_schema = $this->getDomainSchema();
  // fields common to articles and people, only if title is there
  if (!empty($entity_data->title)) {
    if ($entity_data->type !== 'term') {
      // Update title.
      $existing_entity->title = $entity_data->title;
      // Update author.
      $existing_entity->set('uid', $entity_data->uid);
      // Update publication status.
      if ($entity_data->status == '1') {
        $existing_entity->set('status', TRUE);
      }
      if ($entity_data->status == '0') {
         $existing_entity->set('status', FALSE);
      }
    }
  // Flag to track update status.
  $updated = TRUE;
  }
    // Update field_portrait_image_path.
    if (!empty($entity_data->field_portrait_image_path)) {
      $existing_entity->field_portrait_image_path->value = $entity_data->field_portrait_image_path;
    }
    // Update field_portrait_image_alt.
    if (!empty($entity_data->field_portrait_image_alt)) {
      $existing_entity->field_portrait_image_alt->value = $entity_data->field_portrait_image_alt;
    }
    // Set field_departments_programs and simultaneously map domain access on departments
    $dpnames = $entity_data->field_departments_programs;
    foreach ($dpnames as $dpname) {
      $dplookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dpname]);
      if ($dp = reset($dplookup)) {
        $dparray[] = $dp->get('tid')->value;

        // only on depts
        if ($domain_schema['schema'] == 'departments' ) {
          $daarray[] = $dp->get('field_domain_access_target_id')->value;
        }
      }
    }
    if (!empty($dparray)) {
      if ($entity_data->type != 'term' && $entity_data->type != 'media_report_entry') {
        $existing_entity->set('field_departments_programs', $dparray);
      }
      if ($entity_data->type == 'media_report_entry') {
        $existing_entity->set('field_related_department_program', $dparray);
      }
    }
    // only on depts
    if ($domain_schema['schema'] == 'departments' ) {
      if (!empty($daarray)) {
        array_push($daarray, 'departments_as_cornell_edu');
        if ($entity_data->type != 'term') {
          $existing_entity->set('field_domain_access', $daarray);
        }
        if ($entity_data->type == 'term') {
          $existing_entity->set('domain_access', $daarray);
        }
      }
    }


// fields specific to person content type
if ($entity_data->type == 'person') {
      // Update field_netid.
      if (!empty($entity_data->netid)) {
        $existing_entity->field_netid->value = $entity_data->netid;
      }
      // Look up field_primary_department tid by name.
      if (!empty($entity_data->field_primary_department)){
        $pdname = $entity_data->field_primary_department;
        $pdlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $pdname]);
        if ($pd = reset($pdlookup)) {
          $pdarray[] = $pd->get('tid')->value;
        }
        if (!empty($pdarray)) {
          $existing_entity->set('field_primary_department', $pdarray);
        }
      }
      // update primary college
      if (!empty($entity_data->field_primary_college)) {
        $existing_entity->set('field_primary_college', $entity_data->field_primary_college);
      }
      // update affiliated colleges
      $acarray = $entity_data->field_affiliated_colleges;
      if (!empty($acarray)) {
      $existing_entity->set('field_affiliated_colleges', $acarray);
      }
      // update field_exclude_directory
      if ($entity_data->field_exclude_directory == '1') {
        $existing_entity->field_exclude_directory->value = TRUE;
      }
      if ($entity_data->field_exclude_directory == '0') {
        $existing_entity->field_exclude_directory->value = FALSE;
      }
      // update field_hide_contact_info
      if ($entity_data->field_hide_contact_info == '1') {
        $existing_entity->field_hide_contact_info->value = TRUE;
      }
      if ($entity_data->field_hide_contact_info == '0') {
        $existing_entity->field_hide_contact_info->value = FALSE;
      }
      // Update field_job_title.
      if (!empty($entity_data->field_job_title)) {
        $existing_entity->field_job_title->value = $entity_data->field_job_title;
      }else{
        $existing_entity->set('field_job_title', null);
      }
      // Update field_person_last_name.
      if (!empty($entity_data->field_person_last_name)) {
        $existing_entity->field_person_last_name->value = $entity_data->field_person_last_name;
      }
      // Update field_summary.
      if (!empty($entity_data->field_summary)) {
        $existing_entity->field_summary->value = $entity_data->field_summary;
      }
      // Update field_education.
      if (!empty($entity_data->field_education)) {
        $existing_entity->field_education->value = $entity_data->field_education->value;
        $existing_entity->field_education->format = $entity_data->field_education->format;
      }
      // Update field_keywords.
      if (!empty($entity_data->field_keywords)) {
        $existing_entity->field_keywords->value = $entity_data->field_keywords->value;
        $existing_entity->field_keywords->format = $entity_data->field_keywords->format;
      }
      // Update field_body.
      if (!empty($entity_data->field_body)) {
        $existing_entity->field_body->value = $entity_data->field_body->value;
        $existing_entity->field_body->format = $entity_data->field_body->format;
      }
      // Update field_person_type.
      if (!empty($entity_data->field_person_type)) {
        $ptname = $entity_data->field_person_type;
        $ptlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $ptname]);
        if ($pt = reset($ptlookup)) {
          $ptarray[] = $pt->get('tid')->value;
        }
      }
      if (!empty($ptarray)) {
        $existing_entity->set('field_person_type', $ptarray);
      }

      // people taxonomy term lookups only on depts
      if ($domain_schema['schema'] == 'departments' ) {
        // Update field_academic_role.
        if (!empty($entity_data->field_academic_role)) {
          $ararray = [];
          $aruuids = $entity_data->field_academic_role;
          foreach ($aruuids as $aruuid) {
            $arlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $aruuid]);
            if ($ar = reset($arlookup)) {
              $ararray[] = $ar->get('tid')->value;
            }
          }
        }
        if (!empty($ararray)) {
            $existing_entity->set('field_academic_role', $ararray);
        }else{
          $existing_entity->set('field_academic_role', NULL);
        }
        

      
      // end just on depts
      }
      // people taxonomy term lookups only on depts and as
      if ($domain_schema['schema'] == 'departments' ||  $domain_schema['schema'] == 'as') {
        // Update field_research_areas.
        if (!empty($entity_data->field_research_areas)) {
          $raarray = [];
          $rauuids = $entity_data->field_research_areas;
          foreach ($rauuids as $rauuid) {
            $ralookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $rauuid]);
            if ($ra = reset($ralookup)) {
              $raarray[] = $ra->get('tid')->value;
            }
          }
        }
        if (!empty($raarray)) {
          $existing_entity->set('field_research_areas', $raarray);
        }else{
          $existing_entity->set('field_research_areas', NULL);
        }

        // Update field_academic_interests.
        if (!empty($entity_data->field_academic_interests)) {
          $aiarray = [];
          $aiuuids = $entity_data->field_academic_interests;
          foreach ($aiuuids as $aiuuid) {
            $ailookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $aiuuid]);
            if ($ai = reset($ailookup)) {
              $aiarray[] = $ai->get('tid')->value;
            }
          }
        }
        if (!empty($aiarray)) {
          $existing_entity->set('field_academic_interests', $aiarray);
        }else{
          $existing_entity->set('field_academic_interests', NULL);
        }

        //field_overview_research
        if (!empty($entity_data->field_overview_research)) {
        // delete existing paragraphs on person node
        $paragraph_field_name = 'field_overview_research';
        $paragraph_ids = [];
        if (!$existing_entity->get($paragraph_field_name)->isEmpty()) {
          foreach ($existing_entity->get($paragraph_field_name) as $item) {
            $paragraph_ids[] = $item->target_id;
          }
        }

        if (!empty($paragraph_ids)) {
          $paragraph_storage = \Drupal::entityTypeManager()->getStorage('paragraph');
          $paragraphs_to_delete = $paragraph_storage->loadMultiple($paragraph_ids);
          $paragraph_storage->delete($paragraphs_to_delete);
          //\Drupal::messenger()->addStatus(t('Deleted @count paragraphs from node @node_id.', ['@count' => count($paragraph_ids), '@node_id' => $existing_entity->id()]));
        } 
        
        // unset existing paragraphs on person node
        $existing_entity->get($paragraph_field_name)->setValue([]);
        
        // make new paragraphs
        foreach ($entity_data->field_overview_research as $orr) {
          $ordeptarray = [];
          // get array of tids from names
          foreach ($orr->departments_programs as $dept) {
            $ordeptlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $dept]);
          if ($ordpl = reset($ordeptlookup)) {
            $ordeptarray[] = $ordpl->get('tid')->value;
          }
            
          }
          // Create a new paragraph
          $paragraph = Paragraph::create([
            'type' => 'overview_research',
            'field_departments_programs' => $ordeptarray,
            'field_description' => array(
              'value'=>$orr->overview,
              'format'=>$orr->format
              ),
            'field_person_research_focus' => array(
              'value'=>$orr->research,
              'format'=>$orr->format
              ),
          ]);
          $paragraph->save();
          $existing_entity->get('field_overview_research')->appendItem($paragraph);
        }
    
      }
      
    // Update field_link.
    if (!empty($entity_data->field_links)) {
      $linkarray = [];
      foreach ($entity_data->field_links as $link) {
        $linkarray[] = array('uri'=>$link->uri,'title'=>$link->title);
      }
    }
    if (!empty($linkarray)) {
      $existing_entity->set('field_link', $linkarray);
    }else{
      $existing_entity->set('field_link', NULL);
    }
  // end just on depts and as
  }
// end person
}
   

// fields specific to article content type
if ($entity_data->type == 'article') {
      // Update field_page_summary.
      if (!empty($entity_data->field_page_summary)) {
        $existing_entity->field_page_summary->value = $entity_data->field_page_summary;
      }
      // Update field_body.
      if (!empty($entity_data->field_body)) {
        $existing_entity->field_body->value = $entity_data->field_body->value;
        $existing_entity->field_body->format = $entity_data->field_body->format;
      }
      // Update field_bylines.
      if (!empty($entity_data->field_bylines)) {
        $existing_entity->field_bylines->value = $entity_data->field_bylines;
      }
      // Update field_dateline.
      if (!empty($entity_data->field_dateline)) {
        $existing_entity->field_dateline->value = $entity_data->field_dateline;
      }
      // Update field_media_sources.
      if (!empty($entity_data->field_media_sources)) {
        $existing_entity->field_media_sources->value = $entity_data->field_media_sources;
      }
      // Update field_external_media_source.
      if (!empty($entity_data->field_external_media_source)) {
        $existing_entity->field_external_media_source->value = $entity_data->field_external_media_source;
      }
      // Update field_landscape_image_path.
      if (!empty($entity_data->field_landscape_image_path)) {
        $existing_entity->field_landscape_image_path->value = $entity_data->field_landscape_image_path;
      }
      // Update field_landscape_image_alt.
      if (!empty($entity_data->field_landscape_image_alt)) {
        $existing_entity->field_landscape_image_alt->value = $entity_data->field_landscape_image_alt;
      }
      // Update field_thumbnail_image_path.
      if (!empty($entity_data->field_thumbnail_image_path)) {
        $existing_entity->field_thumbnail_image_path->value = $entity_data->field_thumbnail_image_path;
      }
      // Update field_thumbnail_image_alt.
      if (!empty($entity_data->field_thumbnail_image_alt)) {
        $existing_entity->field_thumbnail_image_alt->value = $entity_data->field_thumbnail_image_alt;
      }
      // Update field_related_people.
      if (!empty($entity_data->field_related_people)) {
        $peopleuuids = $entity_data->field_related_people;
        foreach ($peopleuuids as $personuuid) {
          $personlookup = $this->entityTypeManager->getStorage('node')->loadByProperties(['field_remote_uuid' => $personuuid]);
          if ($person = reset($personlookup)) {
            $peoplearray[] = $person->get('nid')->value;
          }
        }
      }
      if (!empty($peoplearray)) {
        $existing_entity->set('field_related_people', $peoplearray);
      }
      // Update field_related_articles.
      if (!empty($entity_data->field_related_articles)) {
        $articleuuids = $entity_data->field_related_articles;
        foreach ($articleuuids as $articleuuid) {
          $articlelookup = $this->entityTypeManager->getStorage('node')->loadByProperties(['field_remote_uuid' => $articleuuid]);
          if ($article = reset($articlelookup)) {
            $articlesarray[] = $article->get('nid')->value;
          }
        }
      }
      if (!empty($articlesarray)) {
        $existing_entity->set('field_related_articles', $articlesarray);
      }
// end articles
}
  
// fields specific to media_report_entry content type
if ($entity_data->type == 'media_report_entry') {
  if (!empty($entity_data->field_outlet_name)) {
    $existing_entity->field_outlet_name->value = $entity_data->field_outlet_name;
  }
  if (!empty($entity_data->field_news_date)) {
    $existing_entity->field_news_date->value = $entity_data->field_news_date;
  }
  if (!empty($entity_data->field_media_report_public_cat)) {
    $existing_entity->field_media_report_public_cat->value = $entity_data->field_media_report_public_cat;
  }
  if (!empty($entity_data->summary)) {
    $existing_entity->summary->value = $entity_data->summary;
  }
  if (!empty($entity_data->body)) {
    $existing_entity->body->value = $entity_data->body;
    $existing_entity->body->format = 'plain_text';
  }
  $linkarray = [];
  if (!empty($entity_data->field_news_link)) {
    $links = explode(',', $entity_data->field_news_link);
    foreach ($links as $key =>$link) {
      $linkarray[$key]['uri'] = $link;
      $linkarray[$key]['title'] = 'Article';
    }
  }
  if (!empty($linkarray)) {
    $existing_entity->set('field_news_link', $linkarray);
  }else{
    $existing_entity->set('field_news_link', NULL);
  }
  if (!empty($entity_data->field_related_people)) {
    $peopleuuids = $entity_data->field_related_people;
    foreach ($peopleuuids as $personuuid) {
      $personlookup = $this->entityTypeManager->getStorage('node')->loadByProperties(['field_remote_uuid' => $personuuid]);
      if ($person = reset($personlookup)) {
        $peoplearray[] = $person->get('nid')->value;
      }
    }
  }
  if (!empty($peoplearray)) {
    $existing_entity->set('field_related_people', $peoplearray);
  }
}

// fields specific to person content type on mediareport
if ($entity_data->type == 'media_report_person') {
    if (!empty($entity_data->field_person_last_name)) {
        $existing_entity->field_person_last_name->value = $entity_data->field_person_last_name;
    }
    if (!empty($entity_data->netid)) {
        $existing_entity->field_netid->value = $entity_data->netid;
    }
    if (!empty($entity_data->uuid)) {
        $existing_entity->field_people_uuid->value = $entity_data->uuid;
        $existing_entity->field_remote_uuid->value = $entity_data->uuid;
    }
    if (!empty($entity_data->field_person_type)) {
      $ptnames = $entity_data->field_person_type;
      foreach ($ptnames as $ptname) {
        $ptlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['name' => $ptname]);
        if ($pt = reset($ptlookup)) {
          $ptarray[] = $pt->get('tid')->value;
        }
      }
      if (!empty($ptarray)) {
        $existing_entity->set('field_person_type', $ptarray);
      }
    }
    $linkarray = [];
    if (!empty($entity_data->field_link)) {
      $links = explode(',', $entity_data->field_link);
      foreach ($links as $key =>$link) {
        $linkarray[$key]['uri'] = $link;
        $linkarray[$key]['title'] = 'Person Record';
      }
    }
    if (!empty($linkarray)) {
      $existing_entity->set('field_link', $linkarray);
    
    }else{
      $existing_entity->set('field_link', NULL);
    }
  
}


// fields specific to taxonomy_term
if ($entity_data->type == 'term') {
      // Update the term name.
      $existing_entity->name = $entity_data->title;
      // set vid
      $existing_entity->set('vid', $entity_data->vocabulary);
      // update field_people_tid
      if (!empty($entity_data->field_people_tid)) {
        $existing_entity->field_people_tid->value = $entity_data->field_people_tid;
      }
      // look up parent tid using field_people_tid of parent in data
      if (!empty($entity_data->parent)) {
        $parentlookup = $this->entityTypeManager->getStorage('taxonomy_term')->loadByProperties(['field_people_tid' => $entity_data->parent]);
          if ($parent = reset($parentlookup)) {
            $existing_entity->set('parent', $parent->get('tid')->value);
            //$existing_entity->parent_target_id->value = $parent->get('tid')->value;
          }
      }
    // end terms
}

    // Save the entity if any fields were updated.
if ($updated) {
  $existing_entity->save();
  // Log a notice that the entity was updated.
  $this->logger->notice('Entity @id updated via webhook notification.', [
    '@id' => $existing_entity->id(),
    '@type' => $entity_data->type,
  ]);
}
}

  /**
   * Deletes an exsiting entity identified in a notification.
   *
   * @param object $existing_entity
   *   Required data from the notification body.
   */
  public function deleteEntity($existing_entity) {
    // Log a notice that the entity was deleted.
    $this->logger->notice('Entity @id deleted via webhook notification.', [
      '@nid' => $existing_entity->id(),
    ]);
    $existing_entity->delete();
  }

  /**
   * Maps and optionally sanitizes payload data for entity creation.
   *
   * @param object $entity_data
   *   Required data from the notification body.
   *
   * @return array $node_values
   *   Structured field values required for creating a basic page node.
   */
  private function mapFieldData($entity_data) {
    // Store values in an array to facilitate node creation.
    $node_values = [];

    // Capture the title from the notification data.
    if (!empty($entity_data->title)) {
        $node_values['title'] = $entity_data->title;
      
    }

    // Capture the body from the notification data.
    if (!empty($entity_data->body)) {
      $node_values['body'] = [
        'value' => $entity_data->body,
        'format' => 'basic_html',
      ];
    }

    return $node_values;
  }

    /**
   * Returns current domain schema based on current host name.
   *
   * @return array $domain_schema
   *   Current domain hostname and project -- as,departments,mediareport.
   */
  private function getDomainSchema() {
    // returns current domain schema 
    $domain_schema = [];
    $host = \Drupal::request()->getHost();
    $as_domains = array('artsci-as.lndo.site','dev-artsci-as.pantheonsite.io','test-artsci-as.pantheonsite.io','live-artsci-as.pantheonsite.io','as.cornell.edu');
    $departments_domains = array('artsci-departments.lndo.site','dev-artsci-departments.pantheonsite.io','test-artsci-departments.pantheonsite.io','live-artsci-departments.pantheonsite.io','departments.as.cornell.edu' );
    $mediareport_domains = array('artsci-mediareport.lndo.site','dev-artsci-mediareport.pantheonsite.io','test-artsci-mediareport.pantheonsite.io','live-artsci-mediareport.pantheonsite.io','mediareport.as.cornell.edu');

    if (!empty($host)) {
        $domain_schema['domain'] = $host;
        if (in_array($host, $as_domains)) {
        $domain_schema['schema'] = 'as';
        }
        if (in_array($host, $departments_domains)) {
        $domain_schema['schema'] = 'departments';
        }
        if (in_array($host, $mediareport_domains)) {
        $domain_schema['schema'] = 'mediareport';
        }
      
    }

    return $domain_schema;
  }
}