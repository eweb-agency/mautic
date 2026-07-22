<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Service;

use MauticPlugin\EwebSaasBundle\Service\SaasWebhookNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Contrat de l'émetteur de webhooks : signature HMAC déterministe,
 * no-op strict sans configuration — la sûreté du chemin de soumission
 * de formulaire repose sur ces deux propriétés.
 */
class SaasWebhookNotifierTest extends TestCase
{
    public function testIsDisabledWithoutConfiguration(): void
    {
        $captured = new CapturingNotifier(new NullLogger(), null, null, null);

        $this->assertFalse($captured->isEnabled());
        $captured->notify('form_submission', ['formId' => 1]);
        $this->assertSame([], $captured->sent, 'no network call without configuration');
    }

    public function testNotifySendsSignedPayload(): void
    {
        $captured = new CapturingNotifier(
            new NullLogger(),
            'https://example.test/api/webhooks/mautic',
            'secret-123',
            'acme',
        );

        $captured->notify('form_submission', ['formId' => 7, 'formName' => 'Contact']);

        $this->assertCount(1, $captured->sent);
        [$body, $timestamp, $signature] = $captured->sent[0];

        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('form_submission', $decoded['event']);
        $this->assertSame('acme', $decoded['slug']);
        $this->assertSame(7, $decoded['data']['formId']);
        // La signature couvre "{timestamp}.{corps}" avec le secret partagé.
        $this->assertSame(
            hash_hmac('sha256', $timestamp.'.'.$body, 'secret-123'),
            $signature,
        );
    }

    public function testSignIsDeterministic(): void
    {
        $notifier = new CapturingNotifier(new NullLogger(), 'https://x', 's', 'a');

        $this->assertSame(
            $notifier->sign('100', '{"a":1}'),
            $notifier->sign('100', '{"a":1}'),
        );
        $this->assertNotSame(
            $notifier->sign('100', '{"a":1}'),
            $notifier->sign('101', '{"a":1}'),
        );
    }
}

/**
 * Capture les envois au lieu de faire du réseau.
 */
final class CapturingNotifier extends SaasWebhookNotifier
{
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    public array $sent = [];

    protected function send(string $body, string $timestamp, string $signature): void
    {
        $this->sent[] = [$body, $timestamp, $signature];
    }
}
