<?php

namespace Drupal\iq_hootsuite_api\Service;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
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
   * Supported MIME types for Hootsuite media uploads.
   */
  const SUPPORTED_MIME_TYPES = [
    'video/mp4',
    'image/gif',
    'image/jpeg',
    'image/jpg',
    'image/png',
  ];

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
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    protected ClientInterface $httpClient,
    protected MessengerInterface $messenger,
    protected StateInterface $state,
    protected FileSystemInterface $fileSystem,
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
  public function uploadImage(File $image): ?array {
    if (!empty($this->images[$image->id()])) {
      return $this->images[$image->id()];
    }
    else {
      $data = $this->registerImage($image);
      if ($data) {
        $this->images[$image->id()] = $data;
        return $data;
      }
    }
    return NULL;
  }

  /**
   * Register image with hootsuite.
   *
   * @param \Drupal\file\Entity\File $image
   *   The file to register.
   * 
   * @return mixed
   *   The media data as array if successful, or FALSE on failure.
   */
  protected function registerImage(File $image) {
    $mimeType = $image->getMimeType();
    $fileUri = $image->getFileUri();
    $tempFile = NULL;

    // Convert unsupported image formats to a supported one.
    if (!in_array($mimeType, self::SUPPORTED_MIME_TYPES)) {
      $converted = $this->convertToSupportedFormat($fileUri, $mimeType);
      if ($converted === NULL) {
        $this->logger->error('Could not convert image from @mime to a supported format.', [
          '@mime' => $mimeType,
        ]);
        return FALSE;
      }
      $fileUri = $converted['uri'];
      $mimeType = $converted['mime_type'];
      $tempFile = $fileUri;
      $this->logger->notice('Converted image from @original to @target for Hootsuite upload.', [
        '@original' => $image->getMimeType(),
        '@target' => $mimeType,
      ]);
    }

    $body = [
      'mimeType' => $mimeType,
      'sizeBytes' => filesize($fileUri),
    ];
    $response = $this->request('post', $this->getEndpointUrl('media'), NULL, $body);
    if (empty($response)) {
      $this->cleanupTempFile($tempFile);
      return FALSE;
    }
    if (!empty($response['data']) && $data = $response['data']) {
      $id = $data['id'];
      if ($this->uploadToAws($fileUri, $mimeType, $data['uploadUrl'])) {
        $i = 0;
        do {
          sleep(1);
          $i++;
          if ($i > 20) {
            $this->messenger->addMessage('Image could not be uploaded, waited for 20 seconds', 'warning');
            $this->logger->warning('Timeout on ready state for image with id @id.', ['@id' => $id]);
            $this->cleanupTempFile($tempFile);
            return FALSE;
          }
          $response = $this->request('get', $this->getEndpointUrl('media') . '/' . $id);
          if (empty($response)) {
            $this->cleanupTempFile($tempFile);
            return FALSE;
          }
          if (!empty($response['data']['state'])) {
            $state = $response['data']['state'];
          }
          else {
            $state = '';
          }
        } while ($state != 'READY');
        $this->logger->notice('Ready state for image id @id, download URL: @url.', ['@id' => $response['data']['id'], '@url' => $response['data']['downloadUrl']]);
        $this->cleanupTempFile($tempFile);
        return $response['data'];
      }
    }
    $this->cleanupTempFile($tempFile);
    return FALSE;
  }

  /**
   * Upload an image to AWS.
   *
   * @param string $file_uri
   *   The URI of the file to upload.
   * @param string $mime_type
   *   The MIME type of the file.
   * @param string $url
   *   The endpoint to send it to.
   */
  protected function uploadToAws(string $file_uri, string $mime_type, string $url) {
    $requestOptions = [
      RequestOptions::HEADERS => [
        'Content-Type' => $mime_type,
        'Content-Length' => filesize($file_uri),
      ],
      RequestOptions::BODY => fopen($file_uri, 'r'),
    ];
    try {
      $this->httpClient->request('put', $url, $requestOptions);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->messenger->addMessage($e->getMessage());
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Converts an image to a Hootsuite-supported format.
   *
   * Uses PHP's GD library to convert unsupported image types (e.g. WebP, BMP,
   * TIFF, AVIF) to JPEG or PNG.
   *
   * @param string $file_uri
   *   The URI of the source image.
   * @param string $original_mime_type
   *   The original MIME type of the image.
   *
   * @return array|null
   *   An array with 'uri' and 'mime_type' keys for the converted file,
   *   or NULL if conversion failed.
   */
  protected function convertToSupportedFormat(string $file_uri, string $original_mime_type): ?array {
    if (!extension_loaded('gd')) {
      $this->logger->error('GD library is not available. Cannot convert image from @mime.', [
        '@mime' => $original_mime_type,
      ]);
      return NULL;
    }

    $realPath = $this->fileSystem->realpath($file_uri);
    if (!$realPath || !file_exists($realPath)) {
      $this->logger->error('File not found for conversion: @uri', ['@uri' => $file_uri]);
      return NULL;
    }

    $gdImage = match ($original_mime_type) {
      'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($realPath) : FALSE,
      'image/bmp', 'image/x-ms-bmp' => function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($realPath) : FALSE,
      'image/tiff' => FALSE,
      'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($realPath) : FALSE,
      default => @imagecreatefromstring(file_get_contents($realPath)),
    };

    if (!$gdImage) {
      $this->logger->error('Could not create GD image from @mime file.', [
        '@mime' => $original_mime_type,
      ]);
      return NULL;
    }

    // Determine target format: use PNG if the image has transparency, JPEG otherwise.
    $hasAlpha = $this->imageHasTransparency($gdImage);
    $targetMimeType = $hasAlpha ? 'image/png' : 'image/jpeg';
    $extension = $hasAlpha ? '.png' : '.jpg';

    $tempDir = $this->fileSystem->getTempDirectory();
    $tempPath = $tempDir . '/hootsuite_converted_' . uniqid() . $extension;

    $success = FALSE;
    if ($hasAlpha) {
      imagesavealpha($gdImage, TRUE);
      $success = imagepng($gdImage, $tempPath, 9);
    }
    else {
      $success = imagejpeg($gdImage, $tempPath, 90);
    }

    imagedestroy($gdImage);

    if (!$success || !file_exists($tempPath)) {
      $this->logger->error('Failed to write converted image to @path.', [
        '@path' => $tempPath,
      ]);
      return NULL;
    }

    return [
      'uri' => $tempPath,
      'mime_type' => $targetMimeType,
    ];
  }

  /**
   * Checks whether a GD image resource has transparency.
   *
   * @param \GdImage $image
   *   The GD image resource.
   *
   * @return bool
   *   TRUE if the image has transparency, FALSE otherwise.
   */
  protected function imageHasTransparency(\GdImage $image): bool {
    $width = imagesx($image);
    $height = imagesy($image);

    // Sample a subset of pixels for performance on large images.
    $step = max(1, (int) (($width * $height) / 10000));
    for ($i = 0; $i < $width * $height; $i += $step) {
      $x = $i % $width;
      $y = (int) ($i / $width);
      $rgba = imagecolorat($image, $x, $y);
      $alpha = ($rgba >> 24) & 0x7F;
      if ($alpha > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Cleans up a temporary file if it exists.
   *
   * @param string|null $tempFile
   *   The path to the temporary file, or NULL.
   */
  protected function cleanupTempFile(?string $tempFile): void {
    if ($tempFile !== NULL && file_exists($tempFile)) {
      @unlink($tempFile);
    }
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
