<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\EwebSaasBundle\Controller\EmailPreferencesController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Fait passer le clic sur le lien de désabonnement par la page Sendly.
 *
 * Seules les lectures (GET, HEAD) sont déviées. Le désabonnement en un clic
 * par en-tête (`List-Unsubscribe-Post`, RFC 8058) est une requête POST : il
 * reste traité par `PublicController::unsubscribeAction` sans jamais passer
 * ici, et il doit le rester.
 *
 * Ce souscripteur tourne à chaque requête, il ne dépend donc d'aucun modèle :
 * la décision qui demande la base est prise par le contrôleur.
 */
final class UnsubscribeEntrySubscriber implements EventSubscriberInterface
{
    private const UNSUBSCRIBE_ROUTE = 'mautic_email_unsubscribe';

    public function __construct(
        private readonly CoreParametersHelper $parameters,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => 'divertUnsubscribeLink'];
    }

    public function divertUnsubscribeLink(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest()
            || self::UNSUBSCRIBE_ROUTE !== $request->attributes->get('_route')
            || !in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)
            || !$this->parameters->get('saas_unsubscribe_page')) {
            return;
        }

        $request->attributes->set('_controller', EmailPreferencesController::class.'::entryAction');
    }
}
