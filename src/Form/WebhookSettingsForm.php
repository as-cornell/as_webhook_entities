<?php

namespace Drupal\as_webhook_entities\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\key\Entity\Key;
use Drupal\key\Plugin\KeyProviderSettableValueInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to configure webhook settings.
 *
 * The token is held by a Key entity using the state provider, so the value
 * lives in the state table and only the variable name is exported with
 * config. Key's config provider would export the value itself.
 */
class WebhookSettingsForm extends ConfigFormBase {

  /**
   * Config settings.
   *
   * @var string
   */
  const SETTINGS = 'as_webhook_entities.settings';

  /**
   * The key ID for the webhook auth token.
   *
   * @var string
   */
  const KEY_ID = 'as_webhook_entities_token';

  /**
   * The state variable a newly created key reads the token from.
   *
   * @var string
   */
  const STATE_KEY = 'as_webhook_entities.token';

  /**
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a WebhookSettingsForm object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(KeyRepositoryInterface $key_repository, StateInterface $state) {
    $this->keyRepository = $key_repository;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('key.repository'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'as_webhook_entities_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      static::SETTINGS,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(static::SETTINGS);

    // Get the current key value.
    $key = $this->keyRepository->getKey(static::KEY_ID);
    $current_value = '';
    if ($key) {
      $key_value = $key->getKeyValue();
      // Show masked value if key exists.
      if ($key_value) {
        $current_value = str_repeat('•', min(strlen($key_value), 40));
      }
    }

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure the authorization token for webhook requests. The token is kept in Drupal state through the Key module, so it is not written out by a config export.') . '</p>',
    ];

    if ($key) {
      $form['current_token'] = [
        '#type' => 'item',
        '#title' => $this->t('Current Token'),
        '#markup' => '<code>' . $current_value . '</code>',
        '#description' => $this->t('The token is stored securely in the database.'),
      ];
    }

    $form['token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Authorization Token'),
      '#required' => !$key,
      '#default_value' => '',
      '#description' => $this->t('Enter the authorization token for webhook requests. Leave empty to keep the current token.'),
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    $form['schema'] = [
      '#type' => 'select',
      '#title' => $this->t('Site schema'),
      '#options' => [
        '' => $this->t('- Auto-detect from request host -'),
        'departments' => $this->t('Departments'),
        'as' => $this->t('AS'),
        'mediareport' => $this->t('Media Report'),
      ],
      '#default_value' => $config->get('schema') ?? '',
      '#description' => $this->t('The schema for this site. Set this explicitly: queued payloads are processed during cron, where auto-detection from the request host is unreliable and can cause schema-specific fields (overview/research, research areas, academic interests/role) to be skipped on update.'),
    ];

    $form['crontrigger'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Process notifications as soon as they arrive.'),
      '#description' => $this->t('Processes the queue in batches right after the listener responds, so the sending site never waits and changes appear within a minute. When off, notifications wait for the next cron run.'),
      '#default_value' => $config->get('crontrigger') ?? FALSE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $token_value = $form_state->getValue('token');

    // Only update token if a new value was provided.
    if (!empty($token_value)) {
      if ($this->saveToken($token_value)) {
        $this->messenger()->addStatus($this->t('The authorization token has been saved.'));
      }
      else {
        $this->messenger()->addError($this->t('The token was not saved: the %key key uses a provider that cannot store values. Set it where that provider reads from; see KEY_SETUP.md.', ['%key' => static::KEY_ID]));
      }
    }
    else {
      $this->messenger()->addWarning($this->t('No changes were made to the token. Enter a new token to update it.'));
    }

    // Save the crontrigger and schema settings to config (fine to export).
    $this->configFactory->getEditable(static::SETTINGS)
      ->set('crontrigger', $form_state->getValue('crontrigger'))
      ->set('schema', $form_state->getValue('schema'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Stores the token where the key's provider will read it.
   *
   * Key's state provider cannot store values, and Key::save() throws
   * KeyValueNotSetException when asked to; the state variable is written
   * directly instead.
   *
   * @param string $token_value
   *   The new token.
   *
   * @return bool
   *   TRUE if the token was stored.
   */
  protected function saveToken(string $token_value): bool {
    $key = $this->keyRepository->getKey(static::KEY_ID);

    if (!$key) {
      $key = Key::create([
        'id' => static::KEY_ID,
        'label' => 'Webhook Entities Authorization Token',
        'description' => 'Authorization token for AS Webhook Entities module',
        'key_type' => 'authentication',
        'key_type_settings' => [],
        'key_provider' => 'state',
        'key_provider_settings' => [
          'state_key' => static::STATE_KEY,
        ],
        'key_input' => 'none',
        'key_input_settings' => [],
      ]);
      $key->save();
    }

    $provider = $key->getKeyProvider();
    if ($provider->getPluginId() === 'state') {
      $this->state->set($provider->getConfiguration()['state_key'], $token_value);
      return TRUE;
    }

    if ($provider instanceof KeyProviderSettableValueInterface) {
      $key->setKeyValue($token_value);
      $key->save();
      if ($provider->getPluginId() === 'config') {
        $this->messenger()->addWarning($this->t('The %key key uses the config provider, so the token will be written out by a config export. Move it to the state provider; see KEY_SETUP.md.', ['%key' => static::KEY_ID]));
      }
      return TRUE;
    }

    return FALSE;
  }

}
