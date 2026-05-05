<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Events;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EwebSaasBundle\Exception\ContactLimitExceededException;
use MauticPlugin\EwebSaasBundle\Service\ContactLimitChecker;
use Psr\Log\LoggerInterface;

/**
 * Doctrine entity listener that intercepts Lead entity BEFORE it is inserted into the database.
 *
 * This is critical because Mautic's LeadController::newAction() calls
 * $contactRepository->saveEntity($lead) directly (bypassing the model layer and Mautic events),
 * then calls $model->saveEntity($lead) which dispatches LEAD_PRE_SAVE — but by that point
 * the INSERT has already been committed.
 *
 * By using Doctrine's prePersist lifecycle event, we can block the INSERT before it happens.
 */
#[AsEntityListener(event: Events::prePersist, entity: Lead::class)]
class DoctrineContactLimitSubscriber
{
    public function __construct(
        private readonly ContactLimitChecker $contactLimitChecker,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Called by Doctrine BEFORE a new Lead entity is persisted (INSERT).
     *
     * @throws ContactLimitExceededException
     */
    public function prePersist(Lead $lead, LifecycleEventArgs $event): void
    {
        if (!$this->contactLimitChecker->isLimitEnabled()) {
            $this->logger->debug('EwebSaasBundle: [Doctrine prePersist] No limit configured, allowing contact creation');

            return;
        }

        $this->logger->info('EwebSaasBundle: [Doctrine prePersist] Checking contact limit before INSERT', [
            'email' => $lead->getEmail(),
        ]);

        // Invalidate cache to get a fresh count (important during batch operations)
        $this->contactLimitChecker->invalidateCache();
        $this->contactLimitChecker->assertCanCreateContact();

        $this->logger->info('EwebSaasBundle: [Doctrine prePersist] Contact creation allowed', [
            'email'        => $lead->getEmail(),
            'currentCount' => $this->contactLimitChecker->getCurrentContactCount(),
            'maxLimit'     => $this->contactLimitChecker->getMaxLimit(),
        ]);
    }
}
