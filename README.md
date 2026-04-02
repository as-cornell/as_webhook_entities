[![Latest Stable Version](https://poser.pugx.org/as-cornell/as_webhook_entities/v)](https://packagist.org/packages/as-cornell/as_webhook_entities)
# AS WEBHOOK ENTITIES (as_webhook_entities)

## INTRODUCTION

![Touchdown!](https://media0.giphy.com/media/v1.Y2lkPTc5MGI3NjExZ3hlZWlwd2ZhaHZ5NWV5NjRyZm1xNWNmOG0zYjR2MnNkcWt3NWp0biZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/3v47jmaB9mO1oFoGtj/giphy.gif "catch it")

Receives webhook notifications from remote systems and creates, updates, or deletes local Drupal entities (people, articles, taxonomy terms) accordingly. Uses a Strategy pattern with per-type handler classes for maintainability and extensibility.

## REQUIREMENTS

### System Requirements
- Drupal 9.5+ or Drupal 10+
- PHP 8.0+

## INSTALLATION

### New Installation

1. **Enable the module:**
   ```bash
   drush en as_webhook_entities -y
   ```

2. **Configure the module settings:**
   - Navigate to `/admin/config/as_webhook_entities/settings`
   - Configure the authorization token and cron trigger settings

3. **Verify the queue worker is registered:**
   ```bash
   drush queue:list
   ```
   You should see `webhook_entities_processor` in the list.

### Upgrading from 1.x to 2.0

1. **Pull the updated code** and clear cache:
   ```bash
   drush cr
   ```

2. **Verify the module is functioning:**
   ```bash
   drush watchdog:show --type=as_webhook_entities --count=10
   ```

## CONFIGURATION

- **Settings UI:** `/admin/config/as_webhook_entities/settings`
- Runs on cron (`webhook_entities_processor` queue, 30 seconds per cron run)
- Cron can be triggered on receipt via the `crontrigger` setting
- Logs create/update/delete operations as `as_webhook_entities`

## ARCHITECTURE

### Strategy Pattern (v2.0+)

Per-type field logic is handled by dedicated handler classes. `WebhookCrudManager` is a thin dispatcher that handles shared logic (title, status, departments/domain access, save) and delegates to the appropriate handler.

```
as_webhook_entities/
├── src/
│   ├── WebhookCrudManager.php              - Thin dispatcher, shared CRUD logic
│   ├── WebhookUuidLookup.php               - Looks up existing entities by UUID
│   ├── WebhookHandler/
│   │   ├── WebhookHandlerInterface.php     - applyCreateFields / applyUpdateFields
│   │   ├── WebhookHandlerBase.php          - Shared lookup helpers
│   │   ├── PersonWebhookHandler.php
│   │   ├── ArticleWebhookHandler.php
│   │   ├── MediaReportEntryWebhookHandler.php
│   │   ├── MediaReportPersonWebhookHandler.php
│   │   └── TermWebhookHandler.php
│   └── Plugin/QueueWorker/
│       └── WebhookEntitiesQueue.php        - Queue processor, event dispatcher
```

### Supported Entity Types

| Payload type | Drupal entity | Handler |
|---|---|---|
| `person` | `node:person` | `PersonWebhookHandler` |
| `article` | `node:article` | `ArticleWebhookHandler` |
| `media_report_entry` | `node:media_report_entry` | `MediaReportEntryWebhookHandler` |
| `media_report_person` | `node:person` | `MediaReportPersonWebhookHandler` |
| `term` | `taxonomy_term` | `TermWebhookHandler` |

### Adding a New Entity Type

1. Create a class in `src/WebhookHandler/` extending `WebhookHandlerBase`
2. Implement `getType()`, `applyCreateFields()`, and `applyUpdateFields()`
3. Register it in `WebhookCrudManager::__construct()`:
   ```php
   'my_type' => new MyTypeWebhookHandler($entity_type_manager),
   ```

## MAINTAINERS

Current maintainers for Drupal 10:

- Mark Wilson (markewilson)

## TROUBLESHOOTING

### Queue items not processing

1. **Check the queue size:**
   ```bash
   drush queue:list
   ```

2. **Run the queue manually:**
   ```bash
   drush queue:run webhook_entities_processor
   ```

3. **Check recent logs:**
   ```bash
   drush watchdog:show --type=as_webhook_entities --count=20
   ```

### Entity not created or updated

1. **Verify the UUID lookup is finding the entity:**
   ```bash
   lando drush php-eval "
   \$lookup = \Drupal::service('as_webhook_entities.uuid_lookup');
   \$entity = \$lookup->findEntity('YOUR-UUID-HERE', 'person');
   echo \$entity ? 'Found: ' . \$entity->id() : 'Not found';
   "
   ```

2. **Push a test payload directly into the queue:**
   ```bash
   lando drush php-eval "
   \$payload = json_encode([
     'event' => 'create',
     'type' => 'person',
     'uuid' => 'test-uuid-001',
     'status' => '1',
     'uid' => '1',
     'title' => 'Test Person',
     'field_departments_programs' => [],
     'field_overview_research' => [],
     'field_links' => [],
   ]);
   \Drupal::queue('webhook_entities_processor')->createItem(\$payload);
   echo 'Queued.' . PHP_EOL;
   "
   lando drush queue:run webhook_entities_processor
   ```

3. **Check for field errors** — if a field in the payload doesn't exist on the destination bundle, the update will fail. Verify field existence:
   ```bash
   lando drush php-eval "
   \$fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'article');
   echo implode(', ', array_keys(\$fields));
   "
   ```

## CHANGELOG

### 2.0
- Refactored `WebhookCrudManager` using the Strategy pattern — per-type field logic extracted into dedicated handler classes under `src/WebhookHandler/`
- Added `WebhookHandlerInterface` and `WebhookHandlerBase` with shared entity lookup helpers (`lookupTermTidsByName`, `lookupTermTidsByProperty`, `lookupNodeNidsByRemoteUuid`)
- Added handler classes: `PersonWebhookHandler`, `ArticleWebhookHandler`, `MediaReportEntryWebhookHandler`, `MediaReportPersonWebhookHandler`, `TermWebhookHandler`
- `WebhookCrudManager` is now a thin dispatcher; shared logic (departments/domain access, node creation, save) remains centralised
- Simplified `WebhookUuidLookup::findEntity()` to a type-keyed lookup map, eliminating duplicate if blocks
- Cleaned up `WebhookEntitiesQueue`: removed redundant UUID ternary, extracted `dispatchCreate()` helper, removed dead code
- Fixed two bugs in `MediaReportEntryWebhookHandler`: `field_news_date` was incorrectly read from `field_outlet_name`, and `field_media_report_public_cat` had a variable typo
- Moved `field_portrait_image_alt` out of shared update logic into `ArticleWebhookHandler` where it belongs
