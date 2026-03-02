# IQ Hootsuite API

A Drupal module that provides integration with the [Hootsuite REST API](https://apidocs.hootsuite.com/docs/api/index.html) for scheduling messages, managing social profiles, and more.

## Requirements

- Drupal 10 / 11
- PHP 8.1+
- Guzzle HTTP client (included with Drupal core)

## Installation

1. Place the module in your `modules/custom` or `modules/contrib` directory.
2. Enable the module:
   ```bash
   drush en iq_hootsuite_api
   ```
## Configuration

1. Navigate to **Administration > Configuration > Web services > Hootsuite API Settings**
   (/admin/config/services/iq_hootsuite_api).
2. Enter your **Client ID** and **Client Secret** from the
   [Hootsuite Developer Portal](https://developer.hootsuite.com).
3. The OAuth2 and API endpoints are pre-filled with Hootsuite defaults. Adjust only if needed.
4. Save the configuration, then click the authentication link to complete the OAuth2 flow.

### OAuth2 Authentication

The module uses the OAuth2 authorization code flow:

1. A user with the *Administer Hootsuite API settings* permission initiates authentication from the settings page.
2. The user is redirected to Hootsuite to grant access.
3. Hootsuite redirects back to /iq_hootsuite_api/callback with an authorization code.
4. The module exchanges the code for access and refresh tokens, stored securely in Drupal's State API.
5. Tokens are automatically refreshed when they expire.

## Usage

The module exposes a service iq_hootsuite_api.client that can be injected into your custom code.

### Dependency injection (recommended)

In your my_module.services.yml:

    services:
      my_module.my_service:
        class: Drupal\my_module\Service\MyService
        arguments:
          - '@iq_hootsuite_api.client'

In your service class:

    use Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface;

    class MyService {
      public function __construct(
        protected HootsuiteApiClientInterface $hootsuiteClient,
      ) {}
    }

### Available methods

| Method | Description |
|---|---|
| getMe() | Retrieve the authenticated member's info |
| getSocialProfiles() | List accessible social profiles |
| scheduleMessage($text, $profileIds, $time, $options) | Schedule a message to social profiles |
| getMessage($messageId) | Retrieve a specific message |
| getMessages($startTime, $endTime, $options) | List outbound messages in a date range |
| deleteMessage($messageId) | Delete a message |
| createMediaUploadUrl($sizeBytes, $mimeType) | Get an S3 upload URL for media |
| request($method, $endpoint, $query, $body) | Make any authenticated API request |
| getEndpointUrl($name) | Build a full URL from a configured endpoint name |
| getHttpClient() | Access the underlying Guzzle HTTP client |

### Examples

    $client = \Drupal::service('iq_hootsuite_api.client');

    // Get authenticated user info.
    $me = $client->getMe();

    // List social profiles.
    $profiles = $client->getSocialProfiles();

    // Schedule a message.
    $result = $client->scheduleMessage(
      'Check out our latest post!',
      ['115185509'],
      '2026-04-01T14:00:00Z',
      ['tags' => ['campaign_spring']],
    );

    // Retrieve messages from the last 7 days.
    $messages = $client->getMessages(
      '2026-02-23T00:00:00Z',
      '2026-03-02T00:00:00Z',
      ['state' => 'SCHEDULED', 'limit' => 10],
    );

    // Generic API call.
    $team = $client->request(
      'GET',
      $client->getEndpointUrl('organizations') . '/626731/teams'
    );

## Permissions

| Permission | Description |
|---|---|
| *Administer Hootsuite API settings* | Access the configuration form and OAuth2 callback |

## API Reference

This module wraps the [Hootsuite REST API v1](https://apidocs.hootsuite.com/docs/api/index.html). Refer to the official documentation for full details on request/response formats.