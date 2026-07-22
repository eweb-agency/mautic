<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * Émetteur de webhooks instance → saas-core (chantier D3.1).
 *
 * Contrat : POST JSON {event, slug, data, timestamp} signé HMAC-SHA256 de
 * "{timestamp}.{corps}" avec le secret partagé, en-têtes x-sendly-slug /
 * x-sendly-timestamp / x-sendly-signature.
 *
 * SÛRETÉ AVANT TOUT : sans configuration (env absente), l'émetteur est un
 * no-op ; en cas d'échec réseau, il journalise et se tait. Un webhook ne
 * doit JAMAIS casser une soumission de formulaire ni une sauvegarde de
 * contact — timeout court (2 s), aucune exception ne sort d'ici.
 */
class SaasWebhookNotifier
{
    private readonly ?string $url;
    private readonly ?string $secret;
    private readonly ?string $slug;

    public function __construct(
        private readonly LoggerInterface $logger,
        ?string $url = null,
        ?string $secret = null,
        ?string $slug = null,
    ) {
        $this->url    = $url ?? $this->env('MAUTIC_SAAS_WEBHOOK_URL');
        $this->secret = $secret ?? $this->env('MAUTIC_SAAS_WEBHOOK_SECRET');
        $this->slug   = $slug ?? $this->env('MAUTIC_ORG_SLUG');
    }

    public function isEnabled(): bool
    {
        return null !== $this->url && null !== $this->secret && null !== $this->slug;
    }

    /**
     * @param array<string, scalar> $data
     */
    public function notify(string $event, array $data = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $timestamp = (string) time();
            $body      = json_encode([
                'event'     => $event,
                'slug'      => $this->slug,
                'data'      => $data,
                'timestamp' => $timestamp,
            ], JSON_THROW_ON_ERROR);
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $this->secret);

            $this->send($body, $timestamp, $signature);
        } catch (\Throwable $e) {
            $this->logger->warning('EwebSaasBundle: webhook emit failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Calcul de signature isolé pour les tests.
     */
    public function sign(string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, (string) $this->secret);
    }

    protected function send(string $body, string $timestamp, string $signature): void
    {
        $ch = curl_init((string) $this->url);
        if (false === $ch) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-sendly-slug: '.$this->slug,
                'x-sendly-timestamp: '.$timestamp,
                'x-sendly-signature: '.$signature,
            ],
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status >= 300 || 0 === $status) {
            $this->logger->warning('EwebSaasBundle: webhook non-2xx: {status}', ['status' => $status]);
        }
    }

    private function env(string $key): ?string
    {
        $value = getenv($key);

        return false === $value || '' === $value ? null : $value;
    }
}
