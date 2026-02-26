<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasLimitBundle\EventListener;

use Mautic\LeadBundle\Event\ImportProcessEvent;
use Mautic\LeadBundle\Event\ImportValidateEvent;
use Mautic\LeadBundle\Event\LeadEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\EwebSaasLimitBundle\Exception\ContactLimitExceededException;
use MauticPlugin\EwebSaasLimitBundle\Service\ContactLimitChecker;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ContactLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ContactLimitChecker $contactLimitChecker,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Note: LEAD_PRE_SAVE is NOT used here because Mautic's LeadController::newAction()
            // calls $contactRepository->saveEntity() directly (bypassing model events) before
            // calling $model->saveEntity(). By the time LEAD_PRE_SAVE fires, the INSERT is already
            // committed and isNew() returns false. The Doctrine prePersist lifecycle event
            // in DoctrineContactLimitSubscriber handles this case instead.
            LeadEvents::LEAD_POST_SAVE      => ['onLeadPostSave', -100],
            LeadEvents::IMPORT_ON_PROCESS   => ['onImportProcess', 200],
            LeadEvents::IMPORT_ON_VALIDATE  => ['onImportValidate', 100],
        ];
    }

    /**
     * Invalidate the contact count cache after a contact is saved.
     * We invalidate on every save (not just new) because Mautic's double-save pattern
     * in LeadController means isNew() is unreliable.
     */
    public function onLeadPostSave(LeadEvent $event): void
    {
        if (!$this->contactLimitChecker->isLimitEnabled()) {
            return;
        }

        $this->contactLimitChecker->invalidateCache();

        $this->logger->debug('EwebSaasLimitBundle: Contact saved, cache invalidated', [
            'contactId' => $event->getLead()->getId(),
        ]);
    }

    /**
     * Block individual rows during CSV import BEFORE the contact is created.
     * Runs at priority 200, well before ImportContactSubscriber (priority 0)
     * which calls LeadModel::import() → saveEntity().
     *
     * The exception will be caught by ImportModel::process() and the row
     * will be marked as "ignored" with our error message.
     *
     * @throws ContactLimitExceededException
     */
    public function onImportProcess(ImportProcessEvent $event): void
    {
        if (!$event->importIsForObject('lead')) {
            return;
        }

        if (!$this->contactLimitChecker->isLimitEnabled()) {
            $this->logger->debug('EwebSaasLimitBundle: No limit configured, allowing import row');

            return;
        }

        $this->logger->info('EwebSaasLimitBundle: Checking contact limit for import row', [
            'importId' => $event->import->getId(),
        ]);

        // Force a fresh count on each row (no cache) to account for contacts
        // that were just inserted in the same batch but not yet cache-invalidated.
        $this->contactLimitChecker->invalidateCache();
        $this->contactLimitChecker->assertCanCreateContact();
    }

    /**
     * Warn the user early during import form validation if the limit is already reached.
     * This gives immediate feedback in the UI before the import is even queued.
     */
    public function onImportValidate(ImportValidateEvent $event): void
    {
        if (!$this->contactLimitChecker->isLimitEnabled()) {
            return;
        }

        if (!$this->contactLimitChecker->canCreateContact()) {
            $this->logger->warning('EwebSaasLimitBundle: Contact limit already reached at import validation', [
                'currentCount' => $this->contactLimitChecker->getCurrentContactCount(),
                'maxLimit'     => $this->contactLimitChecker->getMaxLimit(),
            ]);

            $message = sprintf(
                'Limite de contacts atteinte (%d/%d). Votre abonnement ne permet pas d\'importer plus de contacts. Veuillez augmenter votre abonnement depuis votre portail.',
                $this->contactLimitChecker->getCurrentContactCount(),
                $this->contactLimitChecker->getMaxLimit(),
            );

            $event->getForm()->addError(
                new \Symfony\Component\Form\FormError($message)
            );
        }
    }
}
