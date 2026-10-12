# Webhook Authorization Token Setup with Key Module

## Overview

The AS Webhook Entities module checks incoming webhook requests against an
authorization token, which it reads through Drupal's **Key module** from the
key `as_webhook_entities_token`. Where the token value actually lives depends
on the key's **provider**, and only one choice keeps it out of git:

| Provider | Where the value lives | Exported by `drush cex`? |
| --- | --- | --- |
| `state` (use this) | the `key_value` table, under a state variable | No. Only the variable name is exported |
| `config` | inside the key's own config entity | **Yes. The token is written to `key.key.as_webhook_entities_token.yml`** |

The `config` provider stores the value *in configuration*. Being a Key entity
is not protection on its own: with `config`, the next config export writes the
token to the sync directory and the next commit publishes it. Earlier versions
of this module created the key with the `config` provider and this document
said the opposite. Version 2.16.2 and later create it with `state`.

## Requirements

The Key module must be installed and enabled, in a release that includes the
state provider (the A&S sites run 8.x-1.22):

```bash
composer require drupal/key
drush en key -y
```

## Setting up the token

### Method 1: Admin UI

1. Go to **A&S Webhook Entities Settings**
   (`/admin/config/as_webhook_entities/settings`).
2. Enter the token and click **Save Configuration**.

If the key does not exist yet, the form creates it with the `state` provider
(state variable `as_webhook_entities.token`) and stores the value there. If the
key exists and uses `state`, the form writes to the state variable it names.

The key entity itself is configuration. On an environment where configuration
is locked (`config_readonly`), creating the key from the form will fail; ship
the key's YAML (below) with a deploy instead and set only the value on the
environment.

### Method 2: Drush

Create the key once, then set the value. The key entity can be exported and
committed safely, because it only names the state variable:

```yaml
# config/key.key.as_webhook_entities_token.yml
id: as_webhook_entities_token
label: 'Webhook Entities Authorization Token'
description: 'Authorization token for AS Webhook Entities module'
key_type: authentication
key_type_settings: {  }
key_provider: state
key_provider_settings:
  state_key: as_webhook_entities.token
key_input: none
key_input_settings: {  }
```

```bash
# Set or rotate the value on each environment
drush sset as_webhook_entities.token 'YOUR_TOKEN_HERE'
```

On Pantheon, send the output to a file or `/dev/null`, because Terminus echoes
back the command it ran, token included:

```bash
terminus drush <site>.<env> -- sset as_webhook_entities.token 'YOUR_TOKEN_HERE' > /dev/null 2>&1
```

## Verifying the token

```bash
# Provider and state variable (should say state / as_webhook_entities.token)
drush php-eval "
\$key = \Drupal::service('key.repository')->getKey('as_webhook_entities_token');
echo \$key ? \$key->getKeyProvider()->getPluginId() . ' ' . json_encode(\$key->get('key_provider_settings')) : 'Key NOT configured';
"

# Length and fingerprint, without printing the value
drush php-eval "
\$value = \Drupal::service('key.repository')->getKey('as_webhook_entities_token')?->getKeyValue();
echo \$value ? strlen(\$value) . ' characters, sha256 ' . substr(hash('sha256', \$value), 0, 12) : 'No token value';
"
```

Comparing fingerprints is the safe way to confirm that this receiver and the
sending site (key `as_webhook_update_token` in `as_webhook_update`) hold the
same token. A mismatch makes every webhook fail with 403.

## Moving an existing key from `config` to `state`

If `config/key.key.as_webhook_entities_token.yml` contains `key_provider: config`
or a `key_value:` line, the token is in your config sync directory and very
likely in git history.

1. Copy the current value into state, then switch the provider, on each
   environment:

   ```bash
   drush php-eval "
   \$key = \Drupal::service('key.repository')->getKey('as_webhook_entities_token');
   \Drupal::state()->set('as_webhook_entities.token', \$key->getKeyValue());
   \$key->set('key_provider', 'state');
   \$key->set('key_provider_settings', ['state_key' => 'as_webhook_entities.token']);
   \$key->set('key_input', 'none');
   \$key->save();
   "
   ```

   Check the fingerprint (above) is unchanged before and after.

2. Export the key and commit it. The file should now carry only
   `state_key: as_webhook_entities.token`.

3. **Rotate the token.** Rewriting git history does not remove the old value
   from a host such as GitHub, which keeps serving old commits by SHA until
   its support team garbage-collects them. Treat a token that was committed as
   exposed.

## Configuration export and import

```bash
drush cex -y
grep -rn "key_value" config/key.key.as_webhook_entities_token.yml
# No output is correct. A key_value line means the token was exported.
```

## Security notes

- Use a different token on each environment where practical, and a long random
  one: `openssl rand -hex 32` gives 64 hex characters with nothing to escape
  in a header or a shell.
- Restrict the "Administer keys" permission.
- Do not paste tokens into Slack, email or tickets. Compare fingerprints
  instead.

## Troubleshooting

### "Webhook authorization token is not configured" in logs

The key is missing or its state variable is empty:

```bash
drush php-eval "var_dump(\Drupal::service('key.repository')->getKey('as_webhook_entities_token')?->getKeyProvider()->getPluginId());"
drush php-eval "echo \Drupal::state()->get('as_webhook_entities.token') ? 'state variable set' : 'state variable empty';"
```

### The form says the token was not saved

The key uses a provider that cannot store values (for example `env` or
`file`). Set the value wherever that provider reads from, or move the key to
`state` as described above.

### Key module not installed

```bash
composer require drupal/key
drush en key -y
drush cr
```

---

**Last Updated**: October 12, 2026
**Module Version**: 2.16.2
