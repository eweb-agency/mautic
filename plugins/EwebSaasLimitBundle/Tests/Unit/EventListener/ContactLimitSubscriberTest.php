<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasLimitBundle\Tests\Unit\EventListener;

use Mautic\LeadBundle\Entity\Import;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Event\ImportProcessEvent;
use Mautic\LeadBundle\Event\ImportValidateEvent;
use Mautic\LeadBundle\Event\LeadEvent;
use MauticPlugin\EwebSaasLimitBundle\EventListener\ContactLimitSubscriber;
use MauticPlugin\EwebSaasLimitBundle\Exception\ContactLimitExceededException;
use MauticPlugin\EwebSaasLimitBundle\Service\ContactLimitChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Form\FormInterface;

class ContactLimitSubscriberTest extends TestCase
{
    private ContactLimitChecker&MockObject $contactLimitChecker;

    private ContactLimitSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->contactLimitChecker = $this->createMock(ContactLimitChecker::class);
        $this->subscriber          = new ContactLimitSubscriber(
            $this->contactLimitChecker,
            new NullLogger(),
        );
    }

    public function testGetSubscribedEventsContainsAllExpectedEvents(): void
    {
        $events = ContactLimitSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey('mautic.lead_pre_save', $events);
        $this->assertArrayHasKey('mautic.lead_post_save', $events);
        $this->assertArrayHasKey('mautic.lead_import_on_process', $events);
        $this->assertArrayHasKey('mautic.lead_import_on_validate', $events);
    }

    public function testPreSaveSkipsExistingContacts(): void
    {
        $lead  = new Lead();
        $event = new LeadEvent($lead, false);

        $this->contactLimitChecker->expects($this->never())
            ->method('assertCanCreateContact');

        $this->subscriber->onLeadPreSave($event);
    }

    public function testPreSaveCallsCheckerForNewContacts(): void
    {
        $lead  = new Lead();
        $event = new LeadEvent($lead, true);

        $this->contactLimitChecker->expects($this->once())
            ->method('assertCanCreateContact');

        $this->subscriber->onLeadPreSave($event);
    }

    public function testPreSaveThrowsWhenLimitExceeded(): void
    {
        $lead  = new Lead();
        $event = new LeadEvent($lead, true);

        $this->contactLimitChecker->expects($this->once())
            ->method('assertCanCreateContact')
            ->willThrowException(new ContactLimitExceededException(10, 10));

        $this->expectException(ContactLimitExceededException::class);
        $this->subscriber->onLeadPreSave($event);
    }

    public function testPostSaveInvalidatesCacheForNewContacts(): void
    {
        $lead  = new Lead();
        $event = new LeadEvent($lead, true);

        $this->contactLimitChecker->method('isLimitEnabled')->willReturn(true);
        $this->contactLimitChecker->expects($this->once())
            ->method('invalidateCache');

        $this->subscriber->onLeadPostSave($event);
    }

    public function testPostSaveSkipsExistingContacts(): void
    {
        $lead  = new Lead();
        $event = new LeadEvent($lead, false);

        $this->contactLimitChecker->expects($this->never())
            ->method('invalidateCache');

        $this->subscriber->onLeadPostSave($event);
    }

    public function testPostSaveSkipsWhenLimitNotEnabled(): void
    {
        $lead  = new Lead();
        $event = new LeadEvent($lead, true);

        $this->contactLimitChecker->method('isLimitEnabled')->willReturn(false);
        $this->contactLimitChecker->expects($this->never())
            ->method('invalidateCache');

        $this->subscriber->onLeadPostSave($event);
    }

    // --- IMPORT_ON_PROCESS tests ---

    public function testImportProcessSkipsNonLeadObjects(): void
    {
        $import = new Import();
        $import->setObject('company');

        $event = new ImportProcessEvent($import, new LeadEventLog(), []);

        $this->contactLimitChecker->expects($this->never())
            ->method('assertCanCreateContact');

        $this->subscriber->onImportProcess($event);
    }

    public function testImportProcessSkipsWhenLimitNotEnabled(): void
    {
        $import = new Import();
        $import->setObject('lead');

        $event = new ImportProcessEvent($import, new LeadEventLog(), []);

        $this->contactLimitChecker->method('isLimitEnabled')->willReturn(false);
        $this->contactLimitChecker->expects($this->never())
            ->method('assertCanCreateContact');

        $this->subscriber->onImportProcess($event);
    }

    public function testImportProcessThrowsWhenLimitExceeded(): void
    {
        $import = new Import();
        $import->setObject('lead');

        $event = new ImportProcessEvent($import, new LeadEventLog(), []);

        $this->contactLimitChecker->method('isLimitEnabled')->willReturn(true);
        $this->contactLimitChecker->expects($this->once())
            ->method('invalidateCache');
        $this->contactLimitChecker->expects($this->once())
            ->method('assertCanCreateContact')
            ->willThrowException(new ContactLimitExceededException(2, 2));

        $this->expectException(ContactLimitExceededException::class);
        $this->subscriber->onImportProcess($event);
    }

    public function testImportProcessAllowsWhenBelowLimit(): void
    {
        $import = new Import();
        $import->setObject('lead');

        $event = new ImportProcessEvent($import, new LeadEventLog(), []);

        $this->contactLimitChecker->method('isLimitEnabled')->willReturn(true);
        $this->contactLimitChecker->expects($this->once())
            ->method('assertCanCreateContact'); // does not throw

        $this->subscriber->onImportProcess($event);
    }

    // --- IMPORT_ON_VALIDATE tests ---

    public function testImportValidateSkipsWhenLimitNotEnabled(): void
    {
        $form  = $this->createMock(FormInterface::class);
        $event = new ImportValidateEvent('contacts', $form);

        $this->contactLimitChecker->method('isLimitEnabled')->willReturn(false);

        $form->expects($this->never())->method('addError');

        $this->subscriber->onImportValidate($event);
    }

    public function testImportValidateAddsErrorWhenLimitReached(): void
    {
        $form  = $this->createMock(FormInterface::class);
        $event = new ImportValidateEvent('contacts', $form);

        $this->contactLimitChecker->method('isLimitEnabled')->willReturn(true);
        $this->contactLimitChecker->method('canCreateContact')->willReturn(false);
        $this->contactLimitChecker->method('getCurrentContactCount')->willReturn(10);
        $this->contactLimitChecker->method('getMaxLimit')->willReturn(10);

        $form->expects($this->once())
            ->method('addError')
            ->with($this->callback(function ($error) {
                $message = $error->getMessage();
                $this->assertStringContainsString('Limite de contacts atteinte', $message);
                $this->assertStringContainsString('10/10', $message);
                $this->assertStringContainsString('portail', $message);

                return true;
            }));

        $this->subscriber->onImportValidate($event);
    }

    public function testImportValidateDoesNotAddErrorWhenBelowLimit(): void
    {
        $form  = $this->createMock(FormInterface::class);
        $event = new ImportValidateEvent('contacts', $form);

        $this->contactLimitChecker->method('isLimitEnabled')->willReturn(true);
        $this->contactLimitChecker->method('canCreateContact')->willReturn(true);

        $form->expects($this->never())->method('addError');

        $this->subscriber->onImportValidate($event);
    }
}
