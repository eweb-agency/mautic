<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\EventListener;

use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\EwebSaasBundle\Service\SaasWebhookNotifier;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Pont événements Mautic → webhooks saas-core (D3.1).
 *
 * v1, deux événements à fort signal :
 *  - soumission de formulaire → notification temps réel côté dashboard ;
 *  - contact identifié → invalidation du cache de stats côté saas-core.
 *
 * Le notifier est no-op sans configuration et n'émet jamais d'exception :
 * ces hooks sont sur les chemins critiques (soumission, sauvegarde).
 */
class SaasWebhookSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SaasWebhookNotifier $notifier,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priorité basse : on passe APRÈS toute la chaîne métier — si un
            // autre listener annule/échoue, aucun webhook fantôme.
            FormEvents::FORM_ON_SUBMIT => ['onFormSubmit', -200],
            LeadEvents::LEAD_POST_SAVE => ['onLeadPostSave', -200],
        ];
    }

    public function onFormSubmit(SubmissionEvent $event): void
    {
        $form = $event->getSubmission()->getForm();

        $this->notifier->notify('form_submission', [
            'formId'   => (int) $form->getId(),
            'formName' => (string) $form->getName(),
        ]);
    }

    public function onLeadPostSave(LeadEvent $event): void
    {
        if (!$event->isNew()) {
            return;
        }

        $lead = $event->getLead();
        // Seuls les contacts IDENTIFIÉS comptent — même définition que le
        // quota et les KPI : les visiteurs anonymes du tracking sont ignorés.
        if (null === $lead->getDateIdentified()) {
            return;
        }

        $this->notifier->notify('contact_identified', [
            'leadId' => (int) $lead->getId(),
        ]);
    }
}
