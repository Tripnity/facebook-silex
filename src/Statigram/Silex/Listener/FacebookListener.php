<?php

namespace Statigram\Silex\Listener;

use Statigram\Facebook\Context\ContextFactory;
use Statigram\Facebook\Client;
use Statigram\Facebook\Application;
use Statigram\Facebook\Exception\ContextException;
use Statigram\Facebook\Exception\AuthorizationException;
use Statigram\Facebook\Exception\PermissionException;
use Statigram\Facebook\Exception\RoleException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Log\LoggerInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Initializes the context from the request and sets request attributes based on a matching route.
 *
 * @author Ludovic Fleury <ludo.fleury@gmail.com>
 */
class FacebookListener implements EventSubscriberInterface
{
    private $routes;

    private $contextFactory;

    private $client;

    private $application;

    private $logger;

    /**
     * Constructor.
     */
    public function __construct(RouteCollection $routes, ContextFactory $contextFactory, Client $client, Application $application, LoggerInterface $logger = null)
    {
        $this->routes = $routes;
        $this->contextFactory = $contextFactory;
        $this->client = $client;
        $this->application = $application;
        $this->logger = $logger;
    }

    public function contextualize(GetResponseEvent $event)
    {
        $request = $event->getRequest();

        if ($request->isMethod('post') && $request->request->has('signed_request')) {
            $parameters = $this->client->getSignedRequest();
            $context = $this->contextFactory->create($parameters);

            $this->application->setContext($context);

            if (null !== $this->logger) {
                $this->logger->info(sprintf('Facebook "%s" context defined', $context->getType()));
            }
        }
    }

    public function checkContexts(GetResponseEvent $event) 
    {
        $contexts = $this->getRequirements($event, 'facebook.contexts');
        if (null === $contexts) {
            return; // no context restriction
        }

        if (!$this->application->hasContext()) {
            throw new ContextException('Application is not in a Facebook context');

//            if (null !== $this->logger) {
//                $this->logger->error(sprintf('No Facebook context'));
//            }
        }

        $currentContext = $this->application->getContext();

        if (!in_array($currentContext->getType(), $contexts)) {

            $message = sprintf('Not acceptable Facebook context "%s". Context allowed: [ %s ]', 
                $currentContext->getType(),
                implode(', ', $contexts));

            if (null !== $this->logger) {
                $this->logger->error($message);
            }

            throw new ContextException($message);
        }

        if (null !== $this->logger) {
            $this->logger->info(sprintf('Facebook "%s" context allowed', $currentContext->getType()));
        }
    }

    public function checkAuthorization(GetResponseEvent $event)
    {
        $authorization = $this->getRequirements($event, 'facebook.authorization');
        if (null === $authorization) {
            return; // no authorization requirements
        }

        if (!$this->application->isAuthorized()) {
            $message = sprintf('Facebook application is not authorized by the current user');

            if (null !== $this->logger) {
                $this->logger->error($message);
            }

            throw new AuthorizationException($message);
        }

        // all the api call will now be done on the behalf of the authenticated user 
        // we replace the app access token by the user access token
        if ($this->application->isAuthorized()) {
            $this->client->setAccessToken($this->application->getContext()->getUser()->getAccess()->getToken());
        }

        if (null !== $this->logger) {
            $this->logger->info(sprintf('Facebook application is authorized by the current user (switch to user access token)'));
        }
    }

    public function checkPermissions(GetResponseEvent $event)
    {
        $permissions = $this->getRequirements($event, 'facebook.permissions');
        if (null === $permissions) {
            return; // no permission requirements
        }

        $missing = $this->application->validatePermissions($permissions);

        if (count($missing) > 0) {
            $message = sprintf('Insufficient Facebook permissions. Permissions required: [ %s ]', implode(', ', $missing));

            if (null !== $this->logger) {
                $this->logger->error($message);
            }

            throw new PermissionException($message);
        }

        if (null !== $this->logger) {
            $this->logger->info(sprintf('Facebook application permissions granted'));
        }
    }

    public function checkPageAdmin(GetResponseEvent $event)
    {
        $admin = $this->getRequirements($event, 'facebook.page_admin');
        if (null === $admin) {
            return; // no page admin requirements
        }

        if (!$this->application->isTab()) {
            throw new \LogicException('Unable to check facebook page admin requirement: the context is not a tab');
        }

        if(!$this->application->getContext()->isManageable()) {
            throw new RoleException('Access restricted to the page admin');
        }
    }

    public static function getSubscribedEvents()
    {
        return array(
            KernelEvents::REQUEST => array(
                array('contextualize', 64), 
                array('checkContexts'),
                array('checkAuthorization'),
                array('checkPermissions'),
                array('checkPageAdmin')
            ),
        );
    }

    private function getRequirements(GetResponseEvent $event, $name)
    {
        $request = $event->getRequest();
        $routeName = $request->attributes->get('_route');
        $route = $this->routes->get($routeName);
        
        return $route->getOption($name);
    }
}
