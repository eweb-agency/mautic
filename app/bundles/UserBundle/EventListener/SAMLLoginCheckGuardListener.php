<?php

namespace Mautic\UserBundle\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Catches LightSAML exceptions on /s/saml/login_check (e.g. when Keycloak
 * sends a LogoutRequest instead of an AuthnResponse during SLO) and redirects
 * gracefully instead of showing a Symfony error page.
 */
class SAMLLoginCheckGuardListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 10],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $uri     = $request->getRequestUri();

        // Only handle exceptions on the SAML login_check endpoint
        if (!str_contains($uri, '/saml/login_check')) {
            return;
        }

        $exception = $event->getThrowable();

        // Catch LightSAML context/protocol exceptions (e.g. "Expected StatusResponse")
        if (!str_contains(get_class($exception), 'LightSaml')
            && !str_contains($exception->getMessage(), 'StatusResponse')) {
            return;
        }

        // This is a LogoutRequest from the IdP — clear the local session
        if ($request->hasSession() && $request->getSession()->isStarted()) {
            $request->getSession()->invalidate();
        }

        // Redirect to logout URL (this is a logout, not an auth failure)
        $redirectUrl = getenv('MAUTIC_LOGOUT_REDIRECT_URL') ?: getenv('MAUTIC_AUTH_FAILURE_REDIRECT_URL') ?: '/s/login';

        $event->setResponse(new RedirectResponse($redirectUrl));
    }
}
