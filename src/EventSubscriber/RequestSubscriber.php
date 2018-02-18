<?php

namespace Drupal\shield_agent\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\AdminContext;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Routing\UrlGeneratorTrait;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Acts on all incoming master requests, determining if they are protected.
 */
class RequestSubscriber implements EventSubscriberInterface {

  use UrlGeneratorTrait;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected $routeMatch;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * The admin route context.
   *
   * @var \Drupal\Core\Routing\AdminContext
   */
  protected $adminContext;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $account;

  /**
   * Creates a new RequestSubscriber instance.
   *
   * @param \Drupal\Core\Routing\CurrentRouteMatch $route_match
   *   The current route match.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   * @param \Drupal\Core\Routing\AdminContext $admin_context
   *   The admin route context service.
   * @param \Drupal\Core\Session\AccountProxyInterface $account
   *   The current user.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The URL Generator service.
   */
  public function __construct(CurrentRouteMatch $route_match, ConfigFactoryInterface $config_factory, AdminContext $admin_context, AccountProxyInterface $account, UrlGeneratorInterface $url_generator) {
    $this->routeMatch = $route_match;
    $this->config = $config_factory;
    $this->adminContext = $admin_context;
    $this->account = $account;
    $this->setUrlGenerator($url_generator);
  }

  /**
   * {@inheritdoc}
   */
  public function onRequest(GetResponseEvent $event) {
    if (!$event->isMasterRequest()) {
      return;
    }

    $route_name = $this->routeMatch->getRouteName();
    if (empty($route_name)) {
      // @todo: Determine if further handling of this case should occur.
      return;
    }

    $protecting = $this->config->get('shield_agent.protector')
      ->get('is');
    if (empty($protecting)) {
      return;
    }

    list($_protecting_env, $_protecting_type) = explode('.', $protecting, 2);

    $protected_environments = $this->config->get('shield_agent.protector')
      ->get('environments');
    $protected_environments = !is_array($protected_environments) ? [] : $protected_environments;

    if (isset($protected_environments[$_protecting_env][$_protecting_type])) {
      $protected_environment = $protected_environments[$_protecting_env][$_protecting_type];
      $protected_environment += [
        'routes' => [],
        'allow_routes_admin_context' => FALSE,
      ];

      $route = $this->routeMatch->getRouteObject();
      if ($this->adminContext->isAdminRoute($route)) {
        if (isset($protected_environment['allow_routes_admin_context']) && $protected_environment['allow_routes_admin_context']) {
          return;
        }

        $event->stopPropagation();
        throw new AccessDeniedHttpException();
      }

      if (empty($protected_environment['routes'])) {
        return;
      }

      $shielded_routes_collections = $this->config->get('shield_agent.protector')
        ->get('routes');
      foreach ($protected_environment['routes'] as $collection_name) {
        if (!empty($shielded_routes_collections[$collection_name])) {
          if (in_array($route_name, $shielded_routes_collections[$collection_name])) {
            $event->stopPropagation();
            throw new AccessDeniedHttpException();
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [KernelEvents::REQUEST => [['onRequest', 10]]];
  }

}
