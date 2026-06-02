<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Aggregates Mautic instance statistics for the saas-core dashboard.
 *
 * Queries Mautic's database directly via DBAL to keep things fast and
 * decoupled from Mautic's repositories (which can change between versions).
 *
 * Every metric is cached (filesystem) for {@see self::CACHE_TTL} seconds to
 * shield the database against hot polling from saas-core (e.g. multiple
 * tabs open by the same user).
 */
class StatsAggregator
{
    private const CACHE_TTL = 60; // 1 minute

    private const CACHE_NAMESPACE = 'eweb_saas_stats';

    private readonly CacheInterface $cache;

    private readonly string $prefix;

    public function __construct(
        private readonly Connection $connection,
        private readonly ContactLimitChecker $contactLimitChecker,
        private readonly LoggerInterface $logger,
        ?CacheInterface $cache = null,
    ) {
        $this->cache  = $cache ?? new FilesystemAdapter(self::CACHE_NAMESPACE, self::CACHE_TTL);
        $this->prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) (MAUTIC_TABLE_PREFIX ?? '') : '';
    }

    /**
     * Returns the full stats payload for the saas-core dashboard.
     *
     * @return array{
     *     instance: array{
     *         version: string|null,
     *         locale: string|null,
     *         planTier: string|null,
     *         generatedAt: string,
     *     },
     *     quotas: array{
     *         contacts: array{used: int, max: int, percent: float|null},
     *     },
     *     totals: array{
     *         contacts: int,
     *         segments: int,
     *         campaigns: int,
     *         campaignsActive: int,
     *         forms: int,
     *         emails: int,
     *     },
     *     emails: array{
     *         sentLast30d: int,
     *         openedLast30d: int,
     *         clickedLast30d: int,
     *         bouncedLast30d: int,
     *         openRate: float|null,
     *         clickRate: float|null,
     *         bounceRate: float|null,
     *     },
     *     leads: array{newLast30d: int, identifiedLast30d: int},
     * }
     */
    public function getStats(): array
    {
        return $this->cache->get('stats.full', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            $start = microtime(true);
            try {
                $contactsTotal = $this->countContacts();
                $maxLimit      = $this->contactLimitChecker->getMaxLimit();

                $emailStats = $this->emailStatsLast30d();
                $stats = [
                    'instance' => [
                        'version'     => defined('MAUTIC_VERSION') ? (string) MAUTIC_VERSION : null,
                        'locale'      => $this->envOrNull('MAUTIC_DEFAULT_LOCALE'),
                        'planTier'    => $this->envOrNull('MAUTIC_PLAN_TIER'),
                        'generatedAt' => gmdate('c'),
                    ],
                    'quotas'   => [
                        'contacts' => [
                            'used'    => $contactsTotal,
                            'max'     => $maxLimit,
                            'percent' => $maxLimit > 0
                                ? round(($contactsTotal / $maxLimit) * 100, 2)
                                : null,
                        ],
                    ],
                    'totals'   => [
                        'contacts'        => $contactsTotal,
                        'segments'        => $this->countTable('lead_lists', 'is_published = 1'),
                        'campaigns'       => $this->countTable('campaigns'),
                        'campaignsActive' => $this->countTable('campaigns', 'is_published = 1'),
                        'forms'           => $this->countTable('forms', 'is_published = 1'),
                        'emails'          => $this->countTable('emails'),
                    ],
                    'emails'   => $emailStats,
                    'leads'    => [
                        'newLast30d'        => $this->countContactsNewLast30d(),
                        'identifiedLast30d' => $this->countContactsIdentifiedLast30d(),
                    ],
                ];
            } catch (\Throwable $e) {
                $this->logger->error('EwebSaasBundle: Failed to aggregate stats: {msg}', [
                    'msg'   => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            $this->logger->debug('EwebSaasBundle: Stats aggregated in {ms}ms', [
                'ms' => (int) ((microtime(true) - $start) * 1000),
            ]);

            return $stats;
        });
    }

    /**
     * Returns the last N campaigns with quick send/open/click counts.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     isPublished: bool,
     *     createdAt: string|null,
     *     sent: int,
     *     opened: int,
     *     clicked: int,
     * }>
     */
    public function getRecentCampaigns(int $limit = 5): array
    {
        $limit = max(1, min($limit, 50));

        return $this->cache->get('campaigns.recent.'.$limit, function (ItemInterface $item) use ($limit): array {
            $item->expiresAfter(self::CACHE_TTL);

            $campaignsTable = $this->prefix.'campaigns';
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, name, is_published, date_added
                 FROM {$campaignsTable}
                 ORDER BY date_added DESC
                 LIMIT :limit",
                ['limit' => $limit],
                ['limit' => \PDO::PARAM_INT],
            );

            $out = [];
            foreach ($rows as $row) {
                $cid    = (int) $row['id'];
                $sent   = $this->countEmailStatsForCampaign($cid);
                $opened = $this->countEmailStatsForCampaign($cid, 'is_read = 1');
                $clicked = $this->countCampaignClicks($cid);

                $out[] = [
                    'id'          => $cid,
                    'name'        => (string) $row['name'],
                    'isPublished' => (bool) $row['is_published'],
                    'createdAt'   => $row['date_added'] ? (new \DateTimeImmutable($row['date_added']))->format('c') : null,
                    'sent'        => $sent,
                    'opened'      => $opened,
                    'clicked'     => $clicked,
                ];
            }

            return $out;
        });
    }

    /**
     * Invalidates the whole stats cache. Useful for a manual refresh button.
     */
    public function invalidateCache(): void
    {
        $this->cache->delete('stats.full');
        // We don't know all campaign cache keys; rely on TTL expiry.
        $this->logger->debug('EwebSaasBundle: Stats cache invalidated');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @return array{
     *     sentLast30d: int,
     *     openedLast30d: int,
     *     clickedLast30d: int,
     *     bouncedLast30d: int,
     *     openRate: float|null,
     *     clickRate: float|null,
     *     bounceRate: float|null,
     * }
     */
    private function emailStatsLast30d(): array
    {
        $emailStatsTable = $this->prefix.'email_stats';
        $hitsTable       = $this->prefix.'page_hits';
        $since           = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

        $sent     = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$emailStatsTable} WHERE date_sent >= :since",
            ['since' => $since],
        );
        $opened   = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$emailStatsTable} WHERE date_sent >= :since AND is_read = 1",
            ['since' => $since],
        );
        $bounced  = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$emailStatsTable} WHERE date_sent >= :since AND is_failed = 1",
            ['since' => $since],
        );
        // Clicked: page_hits linked to an email_stat (source='email')
        $clicked = 0;
        try {
            $clicked = (int) $this->connection->fetchOne(
                "SELECT COUNT(DISTINCT source_id) FROM {$hitsTable}
                 WHERE source = 'email' AND date_hit >= :since",
                ['since' => $since],
            );
        } catch (\Throwable $e) {
            // page_hits may be missing in custom installs — swallow.
            $this->logger->debug('EwebSaasBundle: page_hits unavailable: '.$e->getMessage());
        }

        return [
            'sentLast30d'    => $sent,
            'openedLast30d'  => $opened,
            'clickedLast30d' => $clicked,
            'bouncedLast30d' => $bounced,
            'openRate'       => $sent > 0 ? round(($opened / $sent) * 100, 2) : null,
            'clickRate'      => $sent > 0 ? round(($clicked / $sent) * 100, 2) : null,
            'bounceRate'     => $sent > 0 ? round(($bounced / $sent) * 100, 2) : null,
        ];
    }

    private function countContacts(): int
    {
        // Reuse the existing checker so we share its cache and SQL.
        return $this->contactLimitChecker->getCurrentContactCount();
    }

    private function countContactsNewLast30d(): int
    {
        $leadsTable = $this->prefix.'leads';
        $since      = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$leadsTable} WHERE date_added >= :since",
            ['since' => $since],
        );
    }

    private function countContactsIdentifiedLast30d(): int
    {
        $leadsTable = $this->prefix.'leads';
        $since      = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$leadsTable}
             WHERE date_identified IS NOT NULL AND date_identified >= :since",
            ['since' => $since],
        );
    }

    private function countTable(string $table, ?string $whereClause = null): int
    {
        $sql = 'SELECT COUNT(*) FROM '.$this->prefix.$table;
        if ($whereClause) {
            $sql .= ' WHERE '.$whereClause;
        }
        try {
            return (int) $this->connection->fetchOne($sql);
        } catch (\Throwable $e) {
            $this->logger->warning('EwebSaasBundle: count failed for {table}: {msg}', [
                'table' => $table,
                'msg'   => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function countEmailStatsForCampaign(int $campaignId, ?string $extraWhere = null): int
    {
        $emailStatsTable = $this->prefix.'email_stats';
        $sql             = "SELECT COUNT(*) FROM {$emailStatsTable}
                            INNER JOIN ".$this->prefix.'campaign_lead_event_log clel ON clel.lead_id = '.$emailStatsTable.'.lead_id
                            WHERE clel.campaign_id = :cid';
        if ($extraWhere) {
            $sql .= ' AND '.$extraWhere;
        }

        try {
            return (int) $this->connection->fetchOne($sql, ['cid' => $campaignId]);
        } catch (\Throwable $e) {
            // Fallback: if campaign_lead_event_log schema differs, return 0
            return 0;
        }
    }

    private function countCampaignClicks(int $campaignId): int
    {
        try {
            $hitsTable = $this->prefix.'page_hits';

            return (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM {$hitsTable}
                 WHERE source = 'campaign.event' AND source_id = :cid",
                ['cid' => $campaignId],
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function envOrNull(string $key): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($v) && $v !== '' ? $v : null;
    }
}

