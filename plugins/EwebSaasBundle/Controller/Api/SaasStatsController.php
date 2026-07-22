<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Controller\Api;

use MauticPlugin\EwebSaasBundle\Service\StatsAggregator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST controller exposing read-only stats for the saas-core dashboard.
 *
 * All routes are mounted under /api/saas/v1/* (the `/api` prefix is added by
 * Mautic's RouteLoader) and are protected by Mautic's native OAuth2 firewall.
 * saas-core authenticates with a Bearer access token obtained from the
 * instance's /oauth/v2/token endpoint (client_credentials grant).
 *
 * There is no plugin-level auth check anymore: by the time a request reaches
 * this controller, the firewall has already validated the access token.
 *
 * Endpoints:
 *   GET  /api/saas/v1/health        → liveness probe (cheap, no DB)
 *   GET  /api/saas/v1/stats         → aggregated stats (cached 60s)
 *   GET  /api/saas/v1/campaigns     → recent campaigns with KPIs
 *   GET  /api/saas/v1/email-series  → daily sent/opened series (cached 60s)
 *   POST /api/saas/v1/refresh       → invalidate cache so next read is fresh
 *
 * This controller intentionally does NOT extend Mautic's CommonController to
 * avoid pulling the user-bound security context. It's a thin transport layer.
 */
final class SaasStatsController
{
    public function __construct(
        private readonly StatsAggregator $aggregator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function healthAction(): JsonResponse
    {
        return new JsonResponse([
            'status'    => 'ok',
            'version'   => defined('MAUTIC_VERSION') ? (string) MAUTIC_VERSION : null,
            'timestamp' => gmdate('c'),
        ]);
    }

    public function statsAction(): JsonResponse
    {
        try {
            $stats = $this->aggregator->getStats();
        } catch (\Throwable $e) {
            $this->logger->error('EwebSaasBundle: stats endpoint failed: {msg}', ['msg' => $e->getMessage()]);

            return new JsonResponse([
                'error'   => 'stats_failed',
                'message' => 'Failed to aggregate stats. See instance logs.',
            ], 500);
        }

        return new JsonResponse($stats);
    }

    public function campaignsAction(Request $request): JsonResponse
    {
        $limit = (int) $request->query->get('limit', 5);

        try {
            $campaigns = $this->aggregator->getRecentCampaigns($limit);
        } catch (\Throwable $e) {
            $this->logger->error('EwebSaasBundle: campaigns endpoint failed: {msg}', ['msg' => $e->getMessage()]);

            return new JsonResponse([
                'error'   => 'campaigns_failed',
                'message' => 'Failed to read campaigns.',
            ], 500);
        }

        return new JsonResponse([
            'campaigns'   => $campaigns,
            'generatedAt' => gmdate('c'),
        ]);
    }

    public function emailSeriesAction(Request $request): JsonResponse
    {
        $days = (int) $request->query->get('days', 30);

        try {
            $series = $this->aggregator->getEmailSeries($days);
        } catch (\Throwable $e) {
            $this->logger->error('EwebSaasBundle: email-series endpoint failed: {msg}', ['msg' => $e->getMessage()]);

            return new JsonResponse([
                'error'   => 'email_series_failed',
                'message' => 'Failed to build email series.',
            ], 500);
        }

        return new JsonResponse([
            'series'      => $series,
            'generatedAt' => gmdate('c'),
        ]);
    }

    public function scheduledEmailsAction(): JsonResponse
    {
        try {
            $emails = $this->aggregator->getScheduledEmails();
        } catch (\Throwable $e) {
            $this->logger->error('EwebSaasBundle: scheduled-emails endpoint failed: {msg}', ['msg' => $e->getMessage()]);

            return new JsonResponse([
                'error'   => 'scheduled_emails_failed',
                'message' => 'Failed to read scheduled emails.',
            ], 500);
        }

        return new JsonResponse([
            'emails'      => $emails,
            'generatedAt' => gmdate('c'),
        ]);
    }

    public function refreshAction(): JsonResponse
    {
        $this->aggregator->invalidateCache();

        return new JsonResponse(['status' => 'ok'], Response::HTTP_ACCEPTED);
    }
}
