<?php

namespace Drupal\iq_hootsuite_api\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\iq_hootsuite_api\Service\HootsuiteApiClient;
use Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the API Test Console.
 *
 * Provides an interactive admin interface for testing Hootsuite API endpoints.
 */
class ApiTestController extends ControllerBase {

  /**
   * Constructs an ApiTestController object.
   *
   * @param \Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface $hootsuiteApiClient
   *   The Hootsuite API client.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrfToken
   *   The CSRF token generator.
   */
  public function __construct(
    protected HootsuiteApiClientInterface $hootsuiteApiClient,
    protected StateInterface $state,
    protected CsrfTokenGenerator $csrfToken,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('iq_hootsuite_api.client'),
      $container->get('state'),
      $container->get('csrf_token'),
    );
  }

  /**
   * Renders the API Test Console page.
   *
   * @return array
   *   A render array for the test console.
   */
  public function testPage(): array {
    $baseUrl = HootsuiteApiClient::API_BASE_URL;
    $endpoints = HootsuiteApiClient::ENDPOINTS;

    $presets = $this->getEndpointPresets($baseUrl, $endpoints);
    $hasToken = !empty($this->state->get('iq_hootsuite_api.access_token'));
    $authUrl = $this->hootsuiteApiClient->createAuthUrl();

    return [
      '#theme' => 'iq_hootsuite_api_test',
      '#presets' => $presets,
      '#has_token' => $hasToken,
      '#auth_url' => $authUrl,
      '#base_url' => $baseUrl,
      '#attached' => [
        'library' => ['iq_hootsuite_api/api_test'],
        'drupalSettings' => [
          'iqHootsuiteApiTest' => [
            'executeUrl' => Url::fromRoute('iq_hootsuite_api.test_execute')->toString(),
            'presets' => $presets,
            'baseUrl' => $baseUrl,
            'csrfToken' => $this->csrfToken->get('iq_hootsuite_api_test'),
          ],
        ],
      ],
    ];
  }

  /**
   * Executes an API request and returns the result as JSON.
   *
   * This endpoint is called via AJAX from the test console. It accepts
   * the HTTP method, URL, query parameters, body, and custom headers,
   * then makes the actual API request and returns the full response.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming HTTP request containing the API call parameters.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The API response wrapped in a JSON response.
   */
  public function executeRequest(Request $request): JsonResponse {
    // Validate CSRF token.
    $token = $request->headers->get('X-CSRF-Token');
    if (!$this->csrfToken->validate($token, 'iq_hootsuite_api_test')) {
      return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
    }

    $data = json_decode($request->getContent(), TRUE);
    if (empty($data)) {
      return new JsonResponse(['error' => 'Invalid request body.'], 400);
    }

    $method = strtoupper($data['method'] ?? 'GET');
    $url = $data['url'] ?? '';
    $query = $data['query'] ?? [];
    $body = $data['body'] ?? NULL;
    $customHeaders = $data['headers'] ?? [];
    $useAuth = $data['use_auth'] ?? TRUE;

    if (empty($url)) {
      return new JsonResponse(['error' => 'URL is required.'], 400);
    }

    // Remove empty values from query parameters.
    if (is_array($query)) {
      $query = array_filter($query, fn($v) => $v !== '' && $v !== NULL);
    }

    // Build default headers.
    $headers = [
      'Content-Type' => 'application/json',
      'Accept' => '*/*',
    ];

    if ($useAuth) {
      $accessToken = $this->state->get('iq_hootsuite_api.access_token');
      if (empty($accessToken)) {
        return new JsonResponse([
          'error' => 'No access token available. Please authenticate first.',
          'status_code' => 0,
          'headers' => [],
          'body' => NULL,
          'body_raw' => '',
          'time_ms' => 0,
          'is_json' => FALSE,
        ]);
      }
      $headers['Authorization'] = 'Bearer ' . $accessToken;
    }

    // Custom headers override defaults.
    if (is_array($customHeaders)) {
      $headers = array_merge($headers, $customHeaders);
    }

    $requestOptions = [
      RequestOptions::HEADERS => $headers,
      RequestOptions::HTTP_ERRORS => FALSE,
    ];

    if (!empty($query)) {
      $requestOptions[RequestOptions::QUERY] = $query;
    }

    $sentBody = NULL;
    if ($body !== NULL && in_array($method, ['POST', 'PUT', 'PATCH'])) {
      $sentBody = is_string($body) ? $body : json_encode($body);
      $requestOptions[RequestOptions::BODY] = $sentBody;
    }

    // Build the full URL with query string for display.
    $fullUrl = $url;
    if (!empty($query)) {
      $fullUrl .= '?' . http_build_query($query);
    }

    // Capture the sent request details.
    $sentRequest = [
      'method' => $method,
      'url' => $fullUrl,
      'headers' => $headers,
      'body' => $sentBody,
    ];

    $httpClient = $this->hootsuiteApiClient->getHttpClient();
    $startTime = microtime(TRUE);

    try {
      $response = $httpClient->request($method, $url, $requestOptions);
      $elapsed = round((microtime(TRUE) - $startTime) * 1000, 2);

      $responseBody = (string) $response->getBody();
      $responseHeaders = [];
      foreach ($response->getHeaders() as $name => $values) {
        $responseHeaders[$name] = implode(', ', $values);
      }

      $decodedBody = json_decode($responseBody, TRUE);

      return new JsonResponse([
        'status_code' => $response->getStatusCode(),
        'reason' => $response->getReasonPhrase(),
        'headers' => $responseHeaders,
        'body' => $decodedBody !== NULL ? $decodedBody : $responseBody,
        'body_raw' => $responseBody,
        'time_ms' => $elapsed,
        'is_json' => $decodedBody !== NULL,
        'request' => $sentRequest,
      ]);
    }
    catch (GuzzleException $e) {
      $elapsed = round((microtime(TRUE) - $startTime) * 1000, 2);

      // Try to extract response from the exception.
      $statusCode = 0;
      $responseBody = '';
      $responseHeaders = [];

      if (method_exists($e, 'getResponse') && $e->getResponse()) {
        $resp = $e->getResponse();
        $statusCode = $resp->getStatusCode();
        $responseBody = (string) $resp->getBody();
        foreach ($resp->getHeaders() as $name => $values) {
          $responseHeaders[$name] = implode(', ', $values);
        }
      }

      $decodedBody = json_decode($responseBody, TRUE);

      return new JsonResponse([
        'status_code' => $statusCode,
        'error' => $e->getMessage(),
        'reason' => $statusCode ? '' : 'Connection Error',
        'headers' => $responseHeaders,
        'body' => $decodedBody !== NULL ? $decodedBody : $responseBody,
        'body_raw' => $responseBody,
        'time_ms' => $elapsed,
        'is_json' => $decodedBody !== NULL,
        'request' => $sentRequest,
      ]);
    }
  }

  /**
   * Builds the list of endpoint presets from configuration.
   *
   * @param string $baseUrl
   *   The API base URL.
   * @param array $endpoints
   *   The configured endpoint paths keyed by name.
   *
   * @return array
   *   An array of preset definitions.
   */
  protected function getEndpointPresets(string $baseUrl, array $endpoints): array {
    $presets = [];

    if (!empty($endpoints['me'])) {
      $presets[] = [
        'name' => 'Get Me',
        'method' => 'GET',
        'url' => $baseUrl . $endpoints['me'],
        'query' => NULL,
        'body' => NULL,
        'description' => 'Retrieve authenticated user information.',
      ];
    }

    if (!empty($endpoints['social_profiles'])) {
      $presets[] = [
        'name' => 'Get Social Profiles',
        'method' => 'GET',
        'url' => $baseUrl . $endpoints['social_profiles'],
        'query' => NULL,
        'body' => NULL,
        'description' => 'List all accessible social profiles.',
      ];
    }

    if (!empty($endpoints['messages'])) {
      $messagesUrl = $baseUrl . $endpoints['messages'];
      $presets[] = [
        'name' => 'Get Messages',
        'method' => 'GET',
        'url' => $messagesUrl,
        'query' => [
          'startTime' => date('Y-m-d\TH:i:s\Z', strtotime('-7 days')),
          'endTime' => date('Y-m-d\TH:i:s\Z'),
        ],
        'body' => NULL,
        'description' => 'Retrieve outbound messages within a date range.',
      ];
      $presets[] = [
        'name' => 'Get Message by ID',
        'method' => 'GET',
        'url' => $messagesUrl . '/{messageId}',
        'query' => NULL,
        'body' => NULL,
        'description' => 'Retrieve a specific message. Replace {messageId} in the URL.',
      ];
      $presets[] = [
        'name' => 'Schedule Message',
        'method' => 'POST',
        'url' => $messagesUrl,
        'query' => NULL,
        'body' => [
          'text' => 'Hello from API test!',
          'socialProfileIds' => ['PROFILE_ID_HERE'],
          'scheduledSendTime' => date('Y-m-d\TH:i:s\Z', strtotime('+1 hour')),
        ],
        'description' => 'Schedule a message to one or more social profiles.',
      ];
      $presets[] = [
        'name' => 'Delete Message',
        'method' => 'DELETE',
        'url' => $messagesUrl . '/{messageId}',
        'query' => NULL,
        'body' => NULL,
        'description' => 'Delete a message. Replace {messageId} in the URL.',
      ];
    }

    if (!empty($endpoints['media'])) {
      $presets[] = [
        'name' => 'Create Media Upload URL',
        'method' => 'POST',
        'url' => $baseUrl . $endpoints['media'],
        'query' => NULL,
        'body' => [
          'sizeBytes' => 1024,
          'mimeType' => 'image/jpeg',
        ],
        'description' => 'Get a pre-signed URL for uploading media.',
      ];
    }

    if (!empty($endpoints['members'])) {
      $presets[] = [
        'name' => 'Get Members',
        'method' => 'GET',
        'url' => $baseUrl . $endpoints['members'],
        'query' => NULL,
        'body' => NULL,
        'description' => 'List organization members.',
      ];
    }

    if (!empty($endpoints['organizations'])) {
      $presets[] = [
        'name' => 'Get Organizations',
        'method' => 'GET',
        'url' => $baseUrl . $endpoints['organizations'],
        'query' => NULL,
        'body' => NULL,
        'description' => 'List organizations.',
      ];
    }

    // Token endpoint (OAuth2).
    $config = $this->config('iq_hootsuite_api.settings');
    $tokenUrl = $config->get('url_token_endpoint') ?: 'https://platform.hootsuite.com/oauth2/token';
    $clientId = $config->get('client_id') ?: '';
    $clientSecret = $config->get('client_secret') ?: '';

    $presets[] = [
      'name' => 'Refresh Token',
      'method' => 'POST',
      'url' => $tokenUrl,
      'query' => NULL,
      'body' => [
        'grant_type' => 'refresh_token',
        'refresh_token' => 'REFRESH_TOKEN_HERE',
        'scope' => 'offline',
      ],
      'headers' => [
        'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
        'Content-Type' => 'application/x-www-form-urlencoded',
      ],
      'use_auth' => FALSE,
      'description' => 'Exchange a refresh token for a new access token (OAuth2 token endpoint).',
    ];

    return $presets;
  }

}
