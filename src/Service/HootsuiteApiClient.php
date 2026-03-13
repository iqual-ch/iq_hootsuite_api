<?php

namespace Drupal\iq_hootsuite_api\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\Entity\File;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Hootsuite API Client service.
 *
 * Handles OAuth2 authentication and API requests to the Hootsuite platform.
 *
 * @package Drupal\iq_hootsuite_api\Service
 */
class HootsuiteApiClient implements HootsuiteApiClientInterface {

  use StringTranslationTrait;

  /**
   * The API base URL.
   */
  const API_BASE_URL = 'https://platform.hootsuite.com';

  /**
   * API endpoint paths keyed by name.
   */
  const ENDPOINTS = [
    'me' => '/v1/me',
    'social_profiles' => '/v1/socialProfiles',
    'messages' => '/v1/messages',
    'media' => '/v1/media',
    'members' => '/v1/members',
    'organizations' => '/v1/organizations',
  ];

  /**
   * Cache of uploaded images to avoid duplicate uploads.
   *
   * Keyed by Drupal file ID, value is Hootsuite media ID.
   *
   * @var array
   */
  protected array $images = [];

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The module configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a HootsuiteApiClient object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    protected ClientInterface $httpClient,
    protected MessengerInterface $messenger,
    protected StateInterface $state,
  ) {
    $this->config = $config_factory->get('iq_hootsuite_api.settings');
    $this->logger = $logger_factory->get('iq_hootsuite_api');
  }

  /**
   * {@inheritdoc}
   */
  public function createAuthUrl(): string {
    $params = [
      'response_type' => 'code',
      'client_id' => $this->config->get('client_id'),
      'redirect_uri' => $this->getCallbackUrl(),
      'scope' => 'offline',
    ];
    return $this->config->get('url_auth_endpoint') . '?' . http_build_query($params);
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessTokenByAuthCode(?string $code = NULL): bool {
    $authorization = 'Basic ' . base64_encode(
      $this->config->get('client_id') . ':' . $this->config->get('client_secret')
    );

    $headers = [
      'Authorization' => $authorization,
      'Accept' => '*/*',
      'Content-Type' => 'application/x-www-form-urlencoded',
    ];

    if ($code !== NULL) {
      // Exchange authorization code for tokens.
      $request_options = [
        RequestOptions::HEADERS => $headers,
        RequestOptions::FORM_PARAMS => [
          'code' => $code,
          'redirect_uri' => $this->getCallbackUrl(),
          'grant_type' => 'authorization_code',
          'scope' => 'offline',
        ],
      ];

      return $this->executeTokenRequest($request_options);
    }

    // Try to refresh using existing refresh token.
    $refreshToken = $this->state->get('iq_hootsuite_api.refresh_token');
    if (!empty($refreshToken)) {
      $request_options = [
        RequestOptions::HEADERS => $headers,
        RequestOptions::FORM_PARAMS => [
          'refresh_token' => $refreshToken,
          'grant_type' => 'refresh_token',
        ],
      ];

      return $this->executeTokenRequest($request_options);
    }

    $this->logger->warning('No authorization code or refresh token available.');
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function request(string $method, string $endpoint, ?array $query = NULL, ?array $body = NULL): mixed {
    $accessToken = $this->state->get('iq_hootsuite_api.access_token');

    if (empty($accessToken)) {
      $this->logger->error('No access token available. Please authenticate first.');
      return FALSE;
    }

    $request_options = [
      RequestOptions::HEADERS => [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
        'Accept' => '*/*',
      ],
    ];

    if (!empty($body)) {
      $request_options[RequestOptions::BODY] = json_encode($body);
    }

    if (!empty($query)) {
      $request_options[RequestOptions::QUERY] = $query;
    }

    try {
      $response = $this->httpClient->request($method, $endpoint, $request_options);
    }
    catch (GuzzleException $exception) {
      // Handle 401 Unauthorized by refreshing the token.
      if (str_contains($exception->getMessage(), '401 Unauthorized')) {
        $this->logger->notice('Access token expired, attempting refresh.');
        if ($this->getAccessTokenByAuthCode()) {
          return $this->request($method, $endpoint, $query, $body);
        }
        $this->logger->error('Token refresh failed. Re-authentication required.');
        return FALSE;
      }

      // Handle 400 Bad Request.
      if (str_contains($exception->getMessage(), '400 Bad Request')) {
        $this->logger->error('Bad request to Hootsuite API: @error', [
          '@error' => $exception->getMessage(),
        ]);
        return FALSE;
      }

      $this->logger->error('Hootsuite API request failed: @error', [
        '@error' => $exception->getMessage(),
      ]);
      return FALSE;
    }

    $statusCode = $response->getStatusCode();

    // Handle token expiry indicated by status codes.
    if (in_array($statusCode, [401, 403])) {
      if ($this->getAccessTokenByAuthCode()) {
        return $this->request($method, $endpoint, $query, $body);
      }
      return FALSE;
    }

    $responseBody = (string) $response->getBody();
    if (empty($responseBody)) {
      return TRUE;
    }

    return Json::decode($responseBody);
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpointUrl(string $endpoint_name): string {
    $path = self::ENDPOINTS[$endpoint_name] ?? '';
    return self::API_BASE_URL . $path;
  }

  /**
   * {@inheritdoc}
   */
  public function getMe(): mixed {
    return $this->request('GET', $this->getEndpointUrl('me'));
  }

  /**
   * {@inheritdoc}
   */
  public function getSocialProfiles(): mixed {
    return $this->request('GET', $this->getEndpointUrl('social_profiles'));
  }

  /**
   * {@inheritdoc}
   */
  public function scheduleMessage(string $text, array $social_profile_ids, string $scheduled_send_time, array $options = []): mixed {
    $body = array_merge([
      'text' => $text,
      'socialProfileIds' => $social_profile_ids,
      'scheduledSendTime' => $scheduled_send_time,
    ], $options);

    // Log body for debugging.
    $this->logger->debug('Scheduling message with body: @body', [
      '@body' => print_r($body, TRUE),
    ]);

    return $this->request('POST', $this->getEndpointUrl('messages'), NULL, $body);
  }

  /**
   * {@inheritdoc}
   */
  public function getMessage(string $message_id): mixed {
    $url = $this->getEndpointUrl('messages') . '/' . $message_id;
    return $this->request('GET', $url);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteMessage(string $message_id): mixed {
    $url = $this->getEndpointUrl('messages') . '/' . $message_id;
    return $this->request('DELETE', $url);
  }

  /**
   * {@inheritdoc}
   */
  public function getMessages(string $start_time, string $end_time, array $options = []): mixed {
    $query = array_merge([
      'startTime' => $start_time,
      'endTime' => $end_time,
    ], $options);

    return $this->request('GET', $this->getEndpointUrl('messages'), $query);
  }

  /**
   * {@inheritdoc}
   */
  public function createMediaUploadUrl(int $size_bytes, string $mime_type): mixed {
    $body = [
      'sizeBytes' => $size_bytes,
      'mimeType' => $mime_type,
    ];

    return $this->request('POST', $this->getEndpointUrl('media'), NULL, $body);
  }

  /**
   * {@inheritdoc}
   */
  public function uploadImage(File $image): ?string {
    if (!empty($this->images[$image->id()])) {
      return $this->images[$image->id()];
    }
    else {
      $id = $this->registerImage($image);
      if ($id) {
        $this->images[$image->id()] = $id;
        return $id;
      }
    }
    return NULL;
  }

  /**
   * Register image with hootsuite.
   *
   * @param \Drupal\file\Entity\File $image
   *   The file to register.
   */
  protected function registerImage(File $image) {
    $body = [
      'mimeType' => $image->getMimeType(),
      'sizeBytes' => filesize($image->getFileUri()),
    ];
    $response = $this->request('post', $this->getEndpointUrl('media'), NULL, $body);
    if (empty($response)) {
      return FALSE;
    }
    $data = Json::decode($response);
    if (!empty($data['data']) && $data = $data['data']) {
      $id = $data['id'];
      if ($this->uploadToAws($image, $data['uploadUrl'])) {
        $i = 0;
        do {
          sleep(1);
          $i++;
          if ($i > 20) {
            $this->messenger->addMessage('Image could not be uploaded, waited for 20 seconds', 'warning');
            $this->logger->warning('Timeout on ready state for image with id @id.', ['@id' => $id]);
            return FALSE;
          }
          $response = $this->request('get', $this->getEndpointUrl('media') . '/' . $id);
          if (empty($response)) {
            return FALSE;
          }
          $data = Json::decode($response->getContents());
          if (!empty($data['data']['state'])) {
            $state = $data['data']['state'];
          }
          else {
            $state = '';
          }
        } while ($state != 'READY');
        $this->logger->notice('Ready state for image id @id.', ['@id' => $id]);
        return $id;
      }
    }
    return FALSE;
  }

  /**
   * Upload an image to aws.
   *
   * @param \Drupal\file\Entity\File $image
   *   The file to upload.
   * @param string $url
   *   The endpoint to send it to.
   */
  protected function uploadToAws(File $image, string $url) {
    $requestOptions = [
      RequestOptions::HEADERS => [
        'Content-Type' => $image->getMimeType(),
        'Content-Length' => filesize($image->getFileUri()),
      ],
      RequestOptions::BODY => fopen($image->getFileUri(), 'r'),
    ];
    try {
      $this->request('put', $url, $requestOptions);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->messenger->addMessage($e->getMessage());
      return FALSE;
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getHttpClient(): ClientInterface {
    return $this->httpClient;
  }

  /**
   * Executes a token request to the OAuth2 token endpoint.
   *
   * @param array $request_options
   *   The Guzzle request options.
   *
   * @return bool
   *   TRUE if the token was stored successfully, FALSE otherwise.
   */
  protected function executeTokenRequest(array $request_options): bool {
    try {
      $response = $this->httpClient->request(
        'POST',
        $this->config->get('url_token_endpoint'),
        $request_options
      );
    }
    catch (GuzzleException $e) {
      $this->logger->error('Could not acquire token: @error', [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('Could not acquire token: @error', [
        '@error' => $e->getMessage(),
      ]));
      return FALSE;
    }

    $data = Json::decode((string) $response->getBody());

    if ($response->getStatusCode() === 200 && !empty($data['access_token'])) {
      $this->state->set('iq_hootsuite_api.access_token', $data['access_token']);

      if (!empty($data['refresh_token'])) {
        $this->state->set('iq_hootsuite_api.refresh_token', $data['refresh_token']);
      }

      $this->logger->notice('Hootsuite tokens acquired successfully.');
      return TRUE;
    }

    $this->logger->error('Unexpected token response: @response', [
      '@response' => print_r($data, TRUE),
    ]);
    return FALSE;
  }

  /**
   * Gets the OAuth2 callback URL for this site.
   *
   * @return string
   *   The callback URL.
   */
  protected function getCallbackUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/iq_hootsuite_api/callback';
  }

}
