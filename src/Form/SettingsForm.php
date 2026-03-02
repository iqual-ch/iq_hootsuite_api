<?php

namespace Drupal\iq_hootsuite_api\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hootsuite API settings form.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The Hootsuite API client.
   *
   * @var \Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface
   */
  protected HootsuiteApiClientInterface $hootsuiteApiClient;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * Constructs a SettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface $hootsuite_api_client
   *   The Hootsuite API client.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    HootsuiteApiClientInterface $hootsuite_api_client,
    StateInterface $state,
  ) {
    parent::__construct($config_factory);
    $this->hootsuiteApiClient = $hootsuite_api_client;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('iq_hootsuite_api.client'),
      $container->get('state'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'iq_hootsuite_api_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['iq_hootsuite_api.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('iq_hootsuite_api.settings');

    // Client settings fieldset.
    $form['client'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Client Settings'),
    ];

    $form['client']['help'] = [
      '#type' => '#markup',
      '#markup' => $this->t('To get your Hootsuite Client ID, register your application at @link.', [
        '@link' => Link::fromTextAndUrl(
          'https://developer.hootsuite.com',
          Url::fromUri('https://developer.hootsuite.com')
        )->toString(),
      ]),
    ];

    $form['client']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hootsuite Client ID'),
      '#default_value' => $config->get('client_id'),
      '#required' => TRUE,
    ];

    $form['client']['client_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hootsuite Client Secret'),
      '#default_value' => $config->get('client_secret'),
      '#required' => TRUE,
    ];

    // Token status display.
    if ($config->get('client_id') != '') {
      $accessToken = $this->state->get('iq_hootsuite_api.access_token');
      $refreshToken = $this->state->get('iq_hootsuite_api.refresh_token');

      $form['client']['tokens'] = [
        '#type' => 'details',
        '#title' => $this->t('Authentication Status'),
        '#open' => TRUE,
      ];

      if (empty($accessToken) && empty($refreshToken)) {
        $authUrl = $this->hootsuiteApiClient->createAuthUrl();
        $link = Link::fromTextAndUrl($this->t('click here to authenticate'), Url::fromUri($authUrl, [
          'attributes' => ['target' => '_self'],
        ]))->toString();

        $form['client']['tokens']['status'] = [
          '#markup' => $this->t('No tokens are set. To authenticate with Hootsuite, @link.', [
            '@link' => $link,
          ]),
        ];

        $this->messenger()->addWarning($this->t('Access and Refresh Tokens are not set. Save settings and authenticate with Hootsuite.'));
      }
      else {
        $form['client']['tokens']['status'] = [
          '#markup' => $this->t('Tokens are configured. The module will automatically refresh tokens when they expire.'),
        ];
      }
    }

    // OAuth2 settings fieldset.
    $form['auth_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('OAuth2 Settings'),
    ];

    $form['auth_settings']['url_auth_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 Authorize Endpoint'),
      '#default_value' => $config->get('url_auth_endpoint'),
      '#required' => TRUE,
      '#description' => $this->t('Default: https://platform.hootsuite.com/oauth2/auth'),
    ];

    $form['auth_settings']['url_token_endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('OAuth2 Token Endpoint'),
      '#default_value' => $config->get('url_token_endpoint'),
      '#required' => TRUE,
      '#description' => $this->t('Default: https://platform.hootsuite.com/oauth2/token'),
    ];

    // API endpoints fieldset.
    $form['api_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Settings'),
    ];

    $form['api_settings']['url_api_base'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Base URL'),
      '#default_value' => $config->get('url_api_base'),
      '#required' => TRUE,
      '#description' => $this->t('Default: https://platform.hootsuite.com'),
    ];

    $form['api_endpoints'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Endpoints'),
      '#description' => $this->t('Relative paths appended to the API Base URL.'),
    ];

    $endpoints = $config->get('api_endpoints') ?? [];

    $form['api_endpoints']['endpoint_me'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Me'),
      '#default_value' => $endpoints['me'] ?? '/v1/me',
      '#required' => TRUE,
    ];

    $form['api_endpoints']['endpoint_social_profiles'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Social Profiles'),
      '#default_value' => $endpoints['social_profiles'] ?? '/v1/socialProfiles',
      '#required' => TRUE,
    ];

    $form['api_endpoints']['endpoint_messages'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Messages'),
      '#default_value' => $endpoints['messages'] ?? '/v1/messages',
      '#required' => TRUE,
    ];

    $form['api_endpoints']['endpoint_media'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Media'),
      '#default_value' => $endpoints['media'] ?? '/v1/media',
      '#required' => TRUE,
    ];

    $form['api_endpoints']['endpoint_members'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Members'),
      '#default_value' => $endpoints['members'] ?? '/v1/members',
      '#required' => TRUE,
    ];

    $form['api_endpoints']['endpoint_organizations'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Organizations'),
      '#default_value' => $endpoints['organizations'] ?? '/v1/organizations',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('iq_hootsuite_api.settings')
      ->set('client_id', $form_state->getValue('client_id'))
      ->set('client_secret', $form_state->getValue('client_secret'))
      ->set('url_auth_endpoint', $form_state->getValue('url_auth_endpoint'))
      ->set('url_token_endpoint', $form_state->getValue('url_token_endpoint'))
      ->set('url_api_base', $form_state->getValue('url_api_base'))
      ->set('api_endpoints', [
        'me' => $form_state->getValue('endpoint_me'),
        'social_profiles' => $form_state->getValue('endpoint_social_profiles'),
        'messages' => $form_state->getValue('endpoint_messages'),
        'media' => $form_state->getValue('endpoint_media'),
        'members' => $form_state->getValue('endpoint_members'),
        'organizations' => $form_state->getValue('endpoint_organizations'),
      ])
      ->save();

    parent::submitForm($form, $form_state);
  }

}
