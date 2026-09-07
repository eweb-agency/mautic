<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Controller\Api;

use MauticPlugin\EwebSaasBundle\Service\IntegrationStateReader;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /api/saas/v1/integrations — états réels des intégrations pour la
 * page Intégrations du portail (pilule « Connecté » / « N actifs »).
 *
 * Même contrat que SaasStatsController : monté sous /api par le RouteLoader,
 * protégé par le pare-feu OAuth2 natif (client_credentials du portail),
 * couche de transport mince sans contexte utilisateur.
 */
final class SaasIntegrationsController
{
    public function __construct(
        private readonly IntegrationStateReader $reader,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function statesAction(): JsonResponse
    {
        try {
            $states = $this->reader->getStates();
        } catch (\Throwable $e) {
            $this->logger->error('EwebSaasBundle: integrations endpoint failed: {msg}', ['msg' => $e->getMessage()]);

            return new JsonResponse([
                'error'   => 'integrations_failed',
                'message' => 'Failed to read integration states. See instance logs.',
            ], 500);
        }

        return new JsonResponse($states);
    }
}
