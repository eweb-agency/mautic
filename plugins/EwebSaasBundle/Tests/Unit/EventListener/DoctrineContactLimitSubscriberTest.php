<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\EventListener;

use Doctrine\ORM\Event\LifecycleEventArgs;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EwebSaasBundle\EventListener\DoctrineContactLimitSubscriber;
use MauticPlugin\EwebSaasBundle\Exception\ContactLimitExceededException;
use MauticPlugin\EwebSaasBundle\Service\ContactLimitChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The enforcement gate must follow the SAME definition as the counter:
 * the quota counts identified contacts, so anonymous visitor rows must
 * neither count nor be blocked. Before this suite existed, the actual
 * UI-blocking path (Doctrine prePersist) had zero coverage.
 */
class DoctrineContactLimitSubscriberTest extends TestCase
{
    private ContactLimitChecker&MockObject $checker;

    private DoctrineContactLimitSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->checker    = $this->createMock(ContactLimitChecker::class);
        $this->subscriber = new DoctrineContactLimitSubscriber($this->checker, new NullLogger());
    }

    private function event(): LifecycleEventArgs&MockObject
    {
        return $this->createMock(LifecycleEventArgs::class);
    }

    public function testAnonymousVisitorIsNeverBlockedNorCounted(): void
    {
        // A tenant AT their limit keeps page tracking: anonymous rows are not
        // contacts under the pricing definition, so the gate must not even
        // consult the count for them.
        $this->checker->method('isLimitEnabled')->willReturn(true);
        $this->checker->expects($this->never())->method('invalidateCache');
        $this->checker->expects($this->never())->method('assertCanCreateContact');

        $this->subscriber->prePersist(new Lead(), $this->event());
    }

    public function testIdentifiedContactIsEnforcedWithFreshCount(): void
    {
        $this->checker->method('isLimitEnabled')->willReturn(true);
        // Invalidate BEFORE asserting: batch operations would otherwise race
        // a stale cached count past the limit.
        $this->checker->expects($this->once())->method('invalidateCache');
        $this->checker->expects($this->once())->method('assertCanCreateContact');

        $lead = new Lead();
        $lead->setEmail('client@acme.fr');
        $this->subscriber->prePersist($lead, $this->event());
    }

    public function testIdentifiedContactOverLimitIsBlocked(): void
    {
        $this->checker->method('isLimitEnabled')->willReturn(true);
        $this->checker->method('assertCanCreateContact')
            ->willThrowException(new ContactLimitExceededException(100, 100));

        $lead = new Lead();
        $lead->setEmail('bloque@acme.fr');

        $this->expectException(ContactLimitExceededException::class);
        $this->subscriber->prePersist($lead, $this->event());
    }

    public function testNoLimitConfiguredIsANoOp(): void
    {
        $this->checker->method('isLimitEnabled')->willReturn(false);
        $this->checker->expects($this->never())->method('assertCanCreateContact');

        $lead = new Lead();
        $lead->setEmail('libre@acme.fr');
        $this->subscriber->prePersist($lead, $this->event());
    }
}
