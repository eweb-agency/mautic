<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\EventListener;

use MauticPlugin\EwebSaasBundle\Exception\ContactLimitExceededException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Catches ContactLimitExceededException thrown from Doctrine prePersist
 * and converts it into a user-friendly flash message + redirect,
 * instead of showing a raw 500 error page.
 */
class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RouterInterface $router,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 255],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // Unwrap: Doctrine may wrap our exception in various layers
        $contactLimitException = $this->findContactLimitException($exception);

        if (null === $contactLimitException) {
            return;
        }

        $this->logger->warning('EwebSaasBundle: Intercepted ContactLimitExceededException in kernel.exception', [
            'message'      => $contactLimitException->getMessage(),
            'currentCount' => $contactLimitException->getCurrentCount(),
            'maxLimit'     => $contactLimitException->getMaxLimit(),
        ]);

        // Add flash message for the user (with portal upgrade link if available)
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request && $request->hasSession()) {
            $session = $request->getSession();
            if ($session instanceof Session) {
                $session->getFlashBag()->add('error', $contactLimitException->getUserMessage());
            }
        }

        // Redirect to the contacts list
        $redirectUrl = $this->router->generate('mautic_contact_index');
        $response    = new RedirectResponse($redirectUrl);
        $event->setResponse($response);
        $event->allowCustomResponseCode();
    }

    /**
     * Recursively search for ContactLimitExceededException in the exception chain.
     */
    private function findContactLimitException(\Throwable $exception): ?ContactLimitExceededException
    {
        if ($exception instanceof ContactLimitExceededException) {
            return $exception;
        }

        // Check the previous exception in the chain
        $previous = $exception->getPrevious();
        if (null !== $previous) {
            return $this->findContactLimitException($previous);
        }

        return null;
    }
}
