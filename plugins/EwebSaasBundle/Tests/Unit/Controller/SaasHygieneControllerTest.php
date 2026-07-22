<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Controller;

use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\EwebSaasBundle\Controller\Api\SaasHygieneController;
use MauticPlugin\EwebSaasBundle\Service\StatsAggregator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contrat du premier endpoint d'écriture : périmètre DNC strict,
 * validation du payload, idempotence déléguée à addDncForContact,
 * invalidation du cache de stats.
 */
class SaasHygieneControllerTest extends TestCase
{
    private function makeLead(int $id): Lead
    {
        $lead = new Lead();
        $ref  = new \ReflectionProperty(Lead::class, 'id');
        $ref->setValue($lead, $id);

        return $lead;
    }

    public function testDncUnsubscribesMatchedContactsAndInvalidatesCache(): void
    {
        $repository = $this->createMock(LeadRepository::class);
        $repository->method('getContactsByEmail')->willReturnCallback(
            fn (string $email): array => 'known@example.com' === $email
                ? [$this->makeLead(42)]
                : [],
        );

        $dnc = $this->createMock(DoNotContact::class);
        $dnc->expects($this->once())
            ->method('addDncForContact')
            ->with(42, 'email', DNC::UNSUBSCRIBED)
            ->willReturn(new DNC());

        $aggregator = $this->createMock(StatsAggregator::class);
        $aggregator->expects($this->once())->method('invalidateCache');

        $controller = new SaasHygieneController($dnc, $repository, $aggregator, new NullLogger());
        $request    = new Request(content: (string) json_encode([
            'emails' => ['known@example.com', 'unknown@example.com', 'not-an-email'],
        ]));

        $response = $controller->dncAction($request);
        $body     = json_decode((string) $response->getContent(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['requested' => 2, 'matched' => 1, 'dncAdded' => 1], $body);
    }

    public function testRejectsOversizedAndEmptyPayloads(): void
    {
        $controller = new SaasHygieneController(
            $this->createMock(DoNotContact::class),
            $this->createMock(LeadRepository::class),
            $this->createMock(StatsAggregator::class),
            new NullLogger(),
        );

        $tooMany = array_map(
            static fn (int $i): string => "user{$i}@example.com",
            range(1, SaasHygieneController::MAX_EMAILS + 1),
        );
        $response = $controller->dncAction(new Request(content: (string) json_encode(['emails' => $tooMany])));
        $this->assertSame(422, $response->getStatusCode());

        $response = $controller->dncAction(new Request(content: (string) json_encode(['emails' => ['nope']])));
        $this->assertSame(400, $response->getStatusCode());

        $response = $controller->dncAction(new Request(content: 'not json'));
        $this->assertSame(400, $response->getStatusCode());
    }
}
