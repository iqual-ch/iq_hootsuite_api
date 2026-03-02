<?php

namespace Drupal\iq_hootsuite_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Url;

/**
 * Controller for the OAuth2 callback from Hootsuite.
 */
class CallbackController extends ControllerBase {

  /**
   * The Hootsuite API client.
   *
   * @var \Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface
   */
  protected HootsuiteApiClientInterface $hootsuiteApiClient;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a CallbackController object.
   *
   * @param \Drupal\iq_hootsuite_api\Service\HootsuiteApiClientInterface $hootsuite_api_client
   *   The Hootsuite API client.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(
    HootsuiteApiClientInterface $hootsuite_api_client,
    RequestStack $request_stack,
  ) {
    $this->hootsuiteApiClient = $hootsuite_api_client;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('iq_hootsuite_api.client'),
      $container->get('request_stack'),
    );
  }

  /**
   * Handles the OAuth2 callback from Hootsuite.
   *
   * Exchanges the authorization code for access and refresh tokens,
   * then redirects back to the settings page.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the settings form.
   */
  public function callback(): RedirectResponse {
    $request = $this->requestStack->getCurrentRequest();
    $code = $request->query->get('code');

    if (!empty($code)) {
      $success = $this->hootsuiteApiClient->getAccessTokenByAuthCode($code);

      if ($success) {
        $this->messenger()->addStatus($this->t('Successfully authenticated with Hootsuite.'));
      }
      else {
        $this->messenger()->addError($this->t('Failed to authenticate with Hootsuite. Check the logs for details.'));
      }
    }
    else {
      $error = $request->query->get('error');
      $error_description = $request->query->get('error_description', 'Unknown error');
      $this->messenger()->addError($this->t('Hootsuite authorization failed: @error - @description', [
        '@error' => $error ?? 'no_code',
        '@description' => $error_description,
      ]));
    }

    $url = Url::fromRoute('iq_hootsuite_api.settings')->toString();
    return new RedirectResponse($url);
  }

}
