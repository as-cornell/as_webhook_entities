<?php

namespace Drupal\as_webhook_entities\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\key\Entity\Key;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to configure webhook settings.
 *
 * Uses the Key module to securely store the authorization token
 * in the database, preventing it from being exported with config.
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
   * The key repository service.
   *
   * @var \Drupal\key\KeyRepositoryInterface
   */
  protected $keyRepository;

  /**
   * Constructs a WebhookSettingsForm object.
   *
   * @param \Drupal\key\KeyRepositoryInterface $key_repository
   *   The key repository service.
   */
  public function __construct(KeyRepositoryInterface $key_repository) {
    $this->keyRepository = $key_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('key.repository')
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
      '#markup' => '<p>' . $this->t('Configure the authorization token for webhook requests. This token is securely stored using the Key module and will not be exported with configuration.') . '</p>',
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

    $form['crontrigger'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Run cron immediately after receiving add/update/delete via listener.'),
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
      // Check if key already exists.
      $key = $this->keyRepository->getKey(static::KEY_ID);

      if ($key) {
        // Update existing key.
        $key->setKeyValue($token_value);
        $key->save();
      }
      else {
        // Create new key.
        $key = Key::create([
          'id' => static::KEY_ID,
          'label' => 'Webhook Entities Authorization Token',
          'description' => 'Authorization token for AS Webhook Entities module',
          'key_type' => 'authentication',
          'key_type_settings' => [],
          'key_provider' => 'config',
          'key_provider_settings' => [
            'key_value' => $token_value,
          ],
          'key_input' => 'text_field',
          'key_input_settings' => [],
        ]);
        $key->save();
      }

      $this->messenger()->addStatus($this->t('The authorization token has been saved securely.'));
    }
    else {
      $this->messenger()->addWarning($this->t('No changes were made to the token. Enter a new token to update it.'));
    }

    // Save the crontrigger setting to config (this is fine to export).
    $this->configFactory->getEditable(static::SETTINGS)
      ->set('crontrigger', $form_state->getValue('crontrigger'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
