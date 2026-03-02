<?php

namespace Drupal\iq_hootsuite_api\Service;

use GuzzleHttp\ClientInterface;

/**
 * Interface for the Hootsuite API client service.
 */
interface HootsuiteApiClientInterface {

  /**
   * Creates the OAuth2 authorization URL.
   *
   * @return string
   *   The authorization URL to redirect the user to.
   */
  public function createAuthUrl(): string;

  /**
   * Acquires an access token using an authorization code or refresh token.
   *
   * If a code is provided, it exchanges it for access + refresh tokens.
   * If no code is provided, it attempts to refresh using the stored refresh
   * token.
   *
   * @param string|null $code
   *   The authorization code from the OAuth2 callback, or NULL to refresh.
   *
   * @return bool
   *   TRUE if the token was acquired/refreshed successfully, FALSE otherwise.
   */
  public function getAccessTokenByAuthCode(?string $code = NULL): bool;

  /**
   * Makes an authenticated API request to the Hootsuite platform.
   *
   * Automatically handles token refresh on 401 responses.
   *
   * @param string $method
   *   The HTTP method (GET, POST, DELETE, PUT, PATCH).
   * @param string $endpoint
   *   The full API endpoint URL.
   * @param array|null $query
   *   Optional query parameters.
   * @param array|null $body
   *   Optional request body (will be JSON-encoded).
   *
   * @return mixed
   *   The decoded JSON response data, or FALSE on failure.
   */
  public function request(string $method, string $endpoint, ?array $query = NULL, ?array $body = NULL): mixed;

  /**
   * Gets the full URL for a named API endpoint.
   *
   * @param string $endpoint_name
   *   The endpoint name as configured (e.g. 'me', 'messages').
   *
   * @return string
   *   The full URL for the endpoint.
   */
  public function getEndpointUrl(string $endpoint_name): string;

  /**
   * Retrieves the authenticated member's information.
   *
   * @return mixed
   *   The decoded response data, or FALSE on failure.
   */
  public function getMe(): mixed;

  /**
   * Retrieves the social profiles accessible to the authenticated user.
   *
   * @return mixed
   *   The decoded response data, or FALSE on failure.
   */
  public function getSocialProfiles(): mixed;

  /**
   * Schedules a message to one or more social profiles.
   *
   * @param string $text
   *   The message text.
   * @param array $social_profile_ids
   *   Array of social profile IDs.
   * @param string $scheduled_send_time
   *   The scheduled send time in ISO-8601 UTC format (e.g. 2025-01-01T14:00:00Z).
   * @param array $options
   *   Additional options (tags, media, webhookUrls, etc.).
   *
   * @return mixed
   *   The decoded response data, or FALSE on failure.
   */
  public function scheduleMessage(string $text, array $social_profile_ids, string $scheduled_send_time, array $options = []): mixed;

  /**
   * Retrieves a specific message by ID.
   *
   * @param string $message_id
   *   The message ID.
   *
   * @return mixed
   *   The decoded response data, or FALSE on failure.
   */
  public function getMessage(string $message_id): mixed;

  /**
   * Deletes a specific message by ID.
   *
   * @param string $message_id
   *   The message ID.
   *
   * @return mixed
   *   The decoded response data, or FALSE on failure.
   */
  public function deleteMessage(string $message_id): mixed;

  /**
   * Retrieves outbound messages within a date range.
   *
   * @param string $start_time
   *   The start time in ISO-8601 format.
   * @param string $end_time
   *   The end time in ISO-8601 format.
   * @param array $options
   *   Additional options (state, socialProfileIds, limit, cursor).
   *
   * @return mixed
   *   The decoded response data, or FALSE on failure.
   */
  public function getMessages(string $start_time, string $end_time, array $options = []): mixed;

  /**
   * Creates a media upload URL.
   *
   * @param int $size_bytes
   *   The size in bytes of the media file.
   * @param string $mime_type
   *   The MIME type of the media (video/mp4, image/gif, image/jpeg, image/png).
   *
   * @return mixed
   *   The decoded response data containing upload URL, or FALSE on failure.
   */
  public function createMediaUploadUrl(int $size_bytes, string $mime_type): mixed;

  /**
   * Returns the underlying HTTP client.
   *
   * @return \GuzzleHttp\ClientInterface
   *   The Guzzle HTTP client.
   */
  public function getHttpClient(): ClientInterface;

}
