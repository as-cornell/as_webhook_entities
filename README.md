[![Latest Stable Version](https://poser.pugx.org/as-cornell/as_webhook_entities/v)](https://packagist.org/packages/as-cornell/as_webhook_entities)
# AS WEBHOOK ENTITIES (as_webhook_entities)

## INTRODUCTION

Manage remote people, articles and taxonomy termsvia webhook notifications.
Adapted from https://www.bounteous.com/insights/2020/06/08/managing-drupal-webhook-notifications

![Carrier Landing](https://media1.giphy.com/media/v1.Y2lkPTc5MGI3NjExcXp1MHdlcmZlYmw1Njg0OXgzcW52OGlnM3oyeXNvcXF5ZWM0bnB3YSZlcD12MV9pbnRlcm5hbF9naWZfYnlfaWQmY3Q9Zw/Zb6KTAShJAGfkZoIIb/giphy.gif "catch it")

## MAINTAINERS

Current maintainers for Drupal 10:

- Mark Wilson (markewilson)

## CONFIGURATION
- Enable the module as you would any other module
- Configure the global module settings: /admin/config/as_webhook_entities/settings
- Runs on cron
- Cron can be triggered to run on reciept (crontrigger setting)
- Logs create/update/delete (and corn runs) as as_webhook_entities

## FUNCTIONS
- class WebhookEntitiesController
- class WebhookCrudManager
- class WebhookUuidLookup
