<?php

namespace Drupal\social_auth_mailru\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\social_api\Plugin\NetworkManager;
use Drupal\social_auth\SocialAuthDataHandler;
use Drupal\social_auth\SocialAuthUserManager;
use Drupal\social_auth_mailru\MailruAuthManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Returns responses for Simple Mailru Connect module routes.
 */
class MailruAuthController extends ControllerBase {

  /**
   * The network plugin manager.
   *
   * @var \Drupal\social_api\Plugin\NetworkManager
   */
  private $networkManager;

  /**
   * The user manager.
   *
   * @var \Drupal\social_auth\SocialAuthUserManager
   */
  private $userManager;

  /**
   * The mailru authentication manager.
   *
   * @var \Drupal\social_auth_mailru\MailruAuthManager
   */
  private $mailruManager;

  /**
   * Used to access GET parameters.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  private $request;

  /**
   * The Social Auth Data Handler.
   *
   * @var \Drupal\social_auth\SocialAuthDataHandler
   */
  private $dataHandler;


  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * MailruAuthController constructor.
   *
   * @param \Drupal\social_api\Plugin\NetworkManager $network_manager
   *   Used to get an instance of social_auth_mailru network plugin.
   * @param \Drupal\social_auth\SocialAuthUserManager $user_manager
   *   Manages user login/registration.
   * @param \Drupal\social_auth_mailru\MailruAuthManager $mailru_manager
   *   Used to manage authentication methods.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   Used to access GET parameters.
   * @param \Drupal\social_auth\SocialAuthDataHandler $social_auth_data_handler
   *   SocialAuthDataHandler object.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Used for logging errors.
   */
  public function __construct(NetworkManager $network_manager, SocialAuthUserManager $user_manager, MailruAuthManager $mailru_manager, RequestStack $request, SocialAuthDataHandler $social_auth_data_handler, LoggerChannelFactoryInterface $logger_factory) {

    $this->networkManager = $network_manager;
    $this->userManager = $user_manager;
    $this->mailruManager = $mailru_manager;
    $this->request = $request;
    $this->dataHandler = $social_auth_data_handler;
    $this->loggerFactory = $logger_factory;

    // Sets the plugin id.
    $this->userManager->setPluginId('social_auth_mailru');

    // Sets the session keys to nullify if user could not logged in.
    $this->userManager->setSessionKeysToNullify(['access_token', 'oauth2state']);
    $this->setting = $this->config('social_auth_mailru.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.network.manager'),
      $container->get('social_auth.user_manager'),
      $container->get('social_auth_mailru.manager'),
      $container->get('request_stack'),
      $container->get('social_auth.social_auth_data_handler'),
      $container->get('logger.factory')
    );
  }

  /**
   * Response for path 'user/login/mailru'.
   *
   * Redirects the user to Mailru for authentication.
   */
  public function redirectToMailru() {
    /* @var \League\OAuth2\Client\Provider\Mailru false $mailru */
    $mailru = $this->networkManager->createInstance('social_auth_mailru')->getSdk();

    // If mailru client could not be obtained.
    if (!$mailru) {
      drupal_set_message($this->t('Social Auth Mailru not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // Mailru service was returned, inject it to $mailruManager.
    $this->mailruManager->setClient($mailru);

    // Generates the URL where the user will be redirected for Mailru login.
    // If the user did not have email permission granted on previous attempt,
    // we use the re-request URL requesting only the email address.
    $mailru_login_url = $this->mailruManager->getMailruLoginUrl();

    $state = $this->mailruManager->getState();

    $this->dataHandler->set('oauth2state', $state);

    return new TrustedRedirectResponse($mailru_login_url);
  }

  /**
   * Response for path 'user/login/mailru/callback'.
   *
   * Mailru returns the user here after user has authenticated in Mailru.
   */
  public function callback() {
    // Checks if user cancel login via Mailru.
    $error = $this->request->getCurrentRequest()->get('error');
    if ($error == 'access_denied') {
      drupal_set_message($this->t('You could not be authenticated.'), 'error');
      return $this->redirect('user.login');
    }

    /* @var \League\OAuth2\Client\Provider\Mailru false $mailru */
    $mailru = $this->networkManager->createInstance('social_auth_mailru')->getSdk();

    // If Mailru client could not be obtained.
    if (!$mailru) {
      drupal_set_message($this->t('Social Auth Mailru not configured properly. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    $state = $this->dataHandler->get('oauth2state');

    // Retreives $_GET['state'].
    $retrievedState = $this->request->getCurrentRequest()->query->get('state');
    if (empty($retrievedState) || ($retrievedState !== $state)) {
      $this->userManager->nullifySessionKeys();
      drupal_set_message($this->t('Mailru login failed. Unvalid oAuth2 State.'), 'error');
      return $this->redirect('user.login');
    }

    // Saves access token to session.
    $this->dataHandler->set('access_token', $this->mailruManager->getAccessToken());

    $this->mailruManager->setClient($mailru)->authenticate();

    // Gets user's info from Mailru API.
    if (!$mailru_profile = $this->mailruManager->getUserInfo()) {
      drupal_set_message($this->t('Mailru login failed, could not load Mailru profile. Contact site administrator.'), 'error');
      return $this->redirect('user.login');
    }

    // If user information could be retrieved.
    return $this->userManager->authenticateUser($mailru_profile->getName(), $mailru_profile->getEmail(), $mailru_profile->getId(), $this->mailruManager->getAccessToken(), $mailru_profile->getImageUrl, '');
  }

}
