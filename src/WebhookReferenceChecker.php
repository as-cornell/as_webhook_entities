<?php

namespace Drupal\as_webhook_entities;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Finds what on this site still points at a node.
 *
 * Used before a webhook delete. A source record deleted upstream used to take
 * its copy here with it unconditionally, which broke whatever referred to the
 * copy: the media report lost the expert credited on past coverage, artsci-as
 * articles lost a related person. A copy that something still refers to is
 * now kept, and the delete is logged instead. Department sites are unaffected
 * in practice, because references there are removed before people are deleted
 * (artsci-departments scripts/person-deletion-remove-references.php).
 *
 * Counts entity reference fields targeting nodes on nodes, paragraphs, custom
 * blocks and terms. A paragraph counts only while it is still on a page: a
 * paragraph its parent no longer holds (a replaced or deleted revision's)
 * shows nothing to anyone and would otherwise keep every copy forever.
 */
class WebhookReferenceChecker {

  /**
   * Entity types whose fields are checked.
   */
  const REFERRING_TYPES = ['node', 'paragraph', 'block_content', 'taxonomy_term'];

  /**
   * Constructs a WebhookReferenceChecker.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(
    protected Connection $database,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * Lists what still refers to a node.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The node about to be deleted.
   *
   * @return string[]
   *   One "type:id (field)" entry per referring entity; empty when nothing
   *   refers to it. Non-node entities always return empty.
   */
  public function referencesTo(EntityInterface $entity): array {
    if ($entity->getEntityTypeId() !== 'node') {
      return [];
    }
    $found = [];
    foreach ($this->referenceTables() as [$type, $table, $column, $field]) {
      $ids = $this->database->query("SELECT DISTINCT entity_id FROM {" . $table . "} WHERE $column = :id", [':id' => $entity->id()])->fetchCol();
      foreach ($ids as $id) {
        if ($type === 'node' && (int) $id === (int) $entity->id()) {
          continue;
        }
        if ($type === 'paragraph' && !$this->paragraphIsShown((int) $id)) {
          continue;
        }
        $found[] = "$type:$id ($field)";
      }
    }
    return $found;
  }

  /**
   * The tables of entity reference fields that target nodes.
   *
   * @return array
   *   Rows of [entity type, table, target column, field name].
   */
  protected function referenceTables(): array {
    $tables = [];
    $schema = $this->database->schema();
    foreach (self::REFERRING_TYPES as $type) {
      try {
        $definitions = $this->entityFieldManager->getFieldStorageDefinitions($type);
      }
      catch (\Exception $e) {
        // The entity type is not installed on this site.
        continue;
      }
      foreach ($definitions as $name => $definition) {
        if ($definition->isBaseField() || $definition->getType() !== 'entity_reference' || $definition->getSetting('target_type') !== 'node') {
          continue;
        }
        $table = "{$type}__{$name}";
        if ($schema->tableExists($table)) {
          $tables[] = [$type, $table, "{$name}_target_id", $name];
        }
      }
    }
    return $tables;
  }

  /**
   * Whether a paragraph is still on a page, following nested paragraphs up.
   *
   * @param int $id
   *   The paragraph id.
   *
   * @return bool
   *   TRUE when every parent up to a non-paragraph host still holds its child.
   */
  protected function paragraphIsShown(int $id): bool {
    for ($depth = 0; $depth < 6; $depth++) {
      $row = $this->database->query("SELECT parent_type, parent_id, parent_field_name FROM {paragraphs_item_field_data} WHERE id = :id", [':id' => $id])->fetchAssoc();
      if (!$row || !$row['parent_id'] || !$row['parent_field_name']) {
        return FALSE;
      }
      $table = $row['parent_type'] . '__' . $row['parent_field_name'];
      if (!$this->database->schema()->tableExists($table)) {
        return FALSE;
      }
      $held = $this->database->query("SELECT 1 FROM {" . $table . "} WHERE entity_id = :parent AND " . $row['parent_field_name'] . "_target_id = :child", [':parent' => $row['parent_id'], ':child' => $id])->fetchField();
      if (!$held) {
        return FALSE;
      }
      if ($row['parent_type'] !== 'paragraph') {
        return TRUE;
      }
      $id = (int) $row['parent_id'];
    }
    return FALSE;
  }

}
