# Webhook Entities Authorization Token Setup with Key Module

## Overview

The AS Webhook Entities module uses Drupal's **Key module** to securely store the authorization token. This prevents the token from being exported with configuration and committed to version control.

## Setting Up the Authorization Token

### Via Admin UI (Recommended)

1. Navigate to **Configuration → Services → Webhook Entities Settings**
   URL: `/admin/config/services/webhook-entities`

2. Enter your authorization token in the "Authorization Token" field

3. Configure the "Run cron immediately" option if desired

4. Click "Save Configuration"

The token will be securely stored in the database using the Key module.

### Via Drush

```bash
drush php-eval "
\$key = \Drupal\key\Entity\Key::create([
  'id' => 'as_webhook_entities_token',
  'label' => 'Webhook Entities Authorization Token',
  'description' => 'Authorization token for AS Webhook Entities module',
  'key_type' => 'authentication',
  'key_provider' => 'config',
  'key_provider_settings' => ['key_value' => 'YOUR_TOKEN_HERE'],
]);
\$key->save();
echo 'Token saved successfully';
"
```

## Key Configuration Details

- **Key ID**: `as_webhook_entities_token`
- **Key Type**: `authentication`
- **Storage Location**: Database (`key_value` table)

## Verifying the Token

```bash
# Check if token is configured
drush php-eval "
\$key = \Drupal::service('key.repository')->getKey('as_webhook_entities_token');
echo \$key ? 'Token is configured (' . strlen(\$key->getKeyValue()) . ' characters)' : 'Token NOT configured';
"
```

## Migration from Old Config Storage

If upgrading from a version that stored tokens in config:

```bash
# Export old token value
drush cget as_webhook_entities.settings token

# Copy the token value and set it via the new form at:
# /admin/config/services/webhook-entities

# Or via drush:
drush php-eval "
\$old_token = \Drupal::config('as_webhook_entities.settings')->get('token');
if (\$old_token) {
  \$key = \Drupal\key\Entity\Key::create([
    'id' => 'as_webhook_entities_token',
    'label' => 'Webhook Entities Authorization Token',
    'key_type' => 'authentication',
    'key_provider' => 'config',
    'key_provider_settings' => ['key_value' => \$old_token],
  ]);
  \$key->save();
  echo 'Migrated token to Key module';
}
"
```

## Security Notes

✅ **Token is NOT exported** with configuration
✅ **Stored securely** in database
✅ **Environment-specific** - set different tokens per environment
✅ **Access controlled** via Key module permissions

---

**Last Updated**: February 25, 2026
