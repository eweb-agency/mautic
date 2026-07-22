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

    /** Bounds of the `limit` used by {@see getRecentCampaigns()}. Shared with
     *  {@see invalidateCache()} so every cache key it can produce is known. */
    private const CAMPAIGNS_MIN_LIMIT = 1;
    private const CAMPAIGNS_MAX_LIMIT = 50;

    private const CACHE_NAMESPACE = 'eweb_saas_stats';

    /** Fenêtres autorisées pour la série e-mails — ensemble fermé, donc
     *  chaque clé de cache possible est énumérable (cf. invalidateCache). */
    private const SERIES_ALLOWED_DAYS = [7, 30, 90];

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
     *         unsubscribesLast30d: int,
     *         openRate: float|null,
     *         clickRate: float|null,
     *         bounceRate: float|null,
     *         unsubscribeRate: float|null,
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
                $stats      = [
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
     *     sent: int|null,
     *     opened: int|null,
     *     clicked: int|null,
     * }>
     *
     * Null counters mean the metric could not be measured (schema divergence
     * or missing table) — deliberately distinct from a real 0
     */
    public function getRecentCampaigns(int $limit = 5): array
    {
        $limit = max(self::CAMPAIGNS_MIN_LIMIT, min($limit, self::CAMPAIGNS_MAX_LIMIT));

        return $this->cache->get('campaigns.recent.'.$limit, function (ItemInterface $item) use ($limit): array {
            $item->expiresAfter(self::CACHE_TTL);

            $campaignsTable = $this->prefix.'campaigns';
            $rows           = $this->connection->fetchAllAssociative(
                "SELECT id, name, is_published, date_added
                 FROM {$campaignsTable}
                 ORDER BY date_added DESC
                 LIMIT :limit",
                ['limit' => $limit],
                ['limit' => \PDO::PARAM_INT],
            );

            $out = [];
            foreach ($rows as $row) {
                $cid     = (int) $row['id'];
                $sent    = $this->countEmailStatsForCampaign($cid);
                $opened  = $this->countEmailStatsForCampaign($cid, 'es.is_read = 1');
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
     * Daily email series for the dashboard line chart.
     *
     * One point per day over the window: emails SENT that day (date_sent)
     * and opens that HAPPENED that day (date_read) — an open belongs to the
     * day it occurred, not to the day the email was sent. Days without
     * activity are present with zeros: a real 0 must plot as 0, and the
     * chart never has holes.
     */
    public function getEmailSeries(int $days = 30): array
    {
        if (!\in_array($days, self::SERIES_ALLOWED_DAYS, true)) {
            $days = 30;
        }

        return $this->cache->get('emails.series.'.$days, function (ItemInterface $item) use ($days): array {
            $item->expiresAfter(self::CACHE_TTL);

            $emailStatsTable = $this->prefix.'email_stats';
            $since           = (new \DateTimeImmutable(sprintf('-%d days', $days - 1)))->setTime(0, 0);
            $sinceSql        = $since->format('Y-m-d H:i:s');

            $sentRows = $this->connection->fetchAllAssociative(
                "SELECT DATE(date_sent) AS d, COUNT(*) AS c
                 FROM {$emailStatsTable}
                 WHERE date_sent >= :since
                 GROUP BY DATE(date_sent)",
                ['since' => $sinceSql],
            );
            $openRows = $this->connection->fetchAllAssociative(
                "SELECT DATE(date_read) AS d, COUNT(*) AS c
                 FROM {$emailStatsTable}
                 WHERE is_read = 1 AND date_read IS NOT NULL AND date_read >= :since
                 GROUP BY DATE(date_read)",
                ['since' => $sinceSql],
            );

            $sentByDay = [];
            foreach ($sentRows as $row) {
                $sentByDay[(string) $row['d']] = (int) $row['c'];
            }
            $openByDay = [];
            foreach ($openRows as $row) {
                $openByDay[(string) $row['d']] = (int) $row['c'];
            }

            $series = [];
            for ($i = 0; $i < $days; ++$i) {
                $date     = $since->add(new \DateInterval('P'.$i.'D'))->format('Y-m-d');
                $series[] = [
                    'date'   => $date,
                    'sent'   => $sentByDay[$date] ?? 0,
                    'opened' => $openByDay[$date] ?? 0,
                ];
            }

            return $series;
        });
    }

    /**
     * E-mails programmés (publish_up dans les 60 prochains jours) pour le
     * calendrier « Planifier une campagne » du dashboard. Fenêtre FIXE :
     * une seule clé de cache possible.
     */
    public function getScheduledEmails(): array
    {
        return $this->cache->get('emails.scheduled.60', function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            $emailsTable = $this->prefix.'emails';
            $now         = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
            $until       = (new \DateTimeImmutable('+60 days'))->format('Y-m-d H:i:s');

            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, name, publish_up FROM {$emailsTable}
                 WHERE is_published = 1
                   AND publish_up IS NOT NULL
                   AND publish_up BETWEEN :now AND :until
                 ORDER BY publish_up ASC
                 LIMIT 100",
                ['now' => $now, 'until' => $until],
            );

            return array_map(static fn (array $row): array => [
                'id'        => (int) $row['id'],
                'name'      => (string) $row['name'],
                'publishUp' => (string) $row['publish_up'],
            ], $rows);
        });
    }

    /**
     * Invalidates the whole stats cache. Useful for a manual refresh button.
     */
    public function invalidateCache(): void
    {
        $this->cache->delete('stats.full');

        // Series keys are parameterised by a CLOSED set of windows — every
        // possible key is enumerable, same contract as the campaign keys.
        foreach (self::SERIES_ALLOWED_DAYS as $days) {
            $this->cache->delete('emails.series.'.$days);
        }
        $this->cache->delete('emails.scheduled.60');

        // The campaign keys are parameterised by `limit`, which is clamped to a
        // known, bounded range — so every key that can exist is enumerable.
        // Relying on TTL expiry instead made the dashboard's "Refresh" button
        // dishonest: it returned stale campaign KPIs for up to CACHE_TTL
        // seconds while reporting the data as fresh.
        for ($limit = self::CAMPAIGNS_MIN_LIMIT; $limit <= self::CAMPAIGNS_MAX_LIMIT; ++$limit) {
            $this->cache->delete('campaigns.recent.'.$limit);
        }

        // The contact count lives in ContactLimitChecker's OWN cache (a
        // different pool, TTL 300s vs our 60s), and `countContacts()` reads it
        // for both `quotas.contacts.used` and `totals.contacts`. Clearing only
        // our own keys left those two KPIs untouched for up to 5 minutes —
        // the same dishonest-Refresh bug as above, just narrower.
        //
        // Note this is the ONLY unguarded invalidation in the bundle. The
        // three on the write paths (LEAD_POST_SAVE, onImportProcess,
        // prePersist) all sit behind `isLimitEnabled()`, so on an instance
        // with no limit configured — which includes the Enterprise plan,
        // shipped as `maxContacts: -1` — nothing invalidates on create either.
        // Deletions are never covered at all, by anything.
        $this->contactLimitChecker->invalidateCache();

        $this->logger->debug('EwebSaasBundle: Stats cache invalidated');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @return array{
     *     sentLast30d: int,
     *     openedLast30d: int,
     *     clickedLast30d: int,
     *     bouncedLast30d: int,
     *     unsubscribesLast30d: int,
     *     openRate: float|null,
     *     clickRate: float|null,
     *     bounceRate: float|null,
     *     unsubscribeRate: float|null,
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

        // Unsubscribes come from lead_donotcontact, not email_stats.
        $unsubscribed = $this->countUnsubscribesLast30d();

        // Clicked: page_hits linked to an email (source='email'), deduplicated by source_id.
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
            'sentLast30d'         => $sent,
            'openedLast30d'       => $opened,
            'clickedLast30d'      => $clicked,
            'bouncedLast30d'      => $bounced,
            'unsubscribesLast30d' => $unsubscribed,
            'openRate'            => $sent > 0 ? round(($opened / $sent) * 100, 2) : null,
            'clickRate'           => $sent > 0 ? round(($clicked / $sent) * 100, 2) : null,
            'bounceRate'          => $sent > 0 ? round(($bounced / $sent) * 100, 2) : null,
            'unsubscribeRate'     => $sent > 0 ? round(($unsubscribed / $sent) * 100, 2) : null,
        ];
    }

    /**
     * Count contacts that unsubscribed from email in the last 30 days.
     *
     * Mautic stores opt-outs in lead_donotcontact, not in email_stats.
     * We filter on date_added to count only recent unsubscribes.
     */
    private function countUnsubscribesLast30d(): int
    {
        $dncTable = $this->prefix.'lead_donotcontact';
        $since    = (new \DateTimeImmutable('-30 days'))->format('Y-m-d H:i:s');

        try {
            return (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM {$dncTable}
                 WHERE channel = 'email' AND date_added >= :since",
                ['since' => $since],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('EwebSaasBundle: count unsubscribes failed: {msg}', [
                'msg' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function countContacts(): int
    {
        // Reuse the existing checker so we share its cache and SQL — which
        // also means `quotas.contacts.used` and `totals.contacts` follow the
        // quota definition: IDENTIFIED contacts only. Display and enforcement
        // cannot diverge because they are literally the same number.
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

    /**
     * @param string|null $extraWhere Extra SQL predicate. MUST be qualified
     *                                with the `es` alias (email_stats), e.g.
     *                                'es.is_read = 1'.
     *
     * @return int|null null when the metric cannot be measured (schema
     *                  divergence, missing table) — deliberately distinct from
     *                  a real 0, which would make a working campaign look
     *                  broken in the dashboard
     */
    private function countEmailStatsForCampaign(int $campaignId, ?string $extraWhere = null): ?int
    {
        // Join on the campaign EVENT (source/source_id), not just on the lead:
        // joining on lead_id alone counted every email the contact ever
        // received (newsletters, other campaigns), and without DISTINCT each
        // email row was multiplied by the contact's number of executed events
        // — inflating "sent"/"opened" by an order of magnitude.
        // Mautic stamps campaign sends with ['campaign.event', eventId]
        // (EmailBundle/EventListener/CampaignSubscriber), which is what the
        // core itself joins on.
        $emailStatsTable = $this->prefix.'email_stats';
        $eventLogTable   = $this->prefix.'campaign_lead_event_log';
        $sql             = "SELECT COUNT(DISTINCT es.id)
                            FROM {$emailStatsTable} es
                            INNER JOIN {$eventLogTable} clel
                              ON es.source_id = clel.event_id
                             AND es.lead_id = clel.lead_id
                            WHERE es.source = 'campaign.event'
                              AND clel.campaign_id = :cid";
        if ($extraWhere) {
            $sql .= ' AND '.$extraWhere;
        }

        try {
            return (int) $this->connection->fetchOne($sql, ['cid' => $campaignId]);
        } catch (\Throwable $e) {
            $this->logger->warning('EwebSaasBundle: campaign email stats unmeasurable', [
                'campaignId' => $campaignId,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return int|null null when the metric cannot be measured (see
     *                  {@see countEmailStatsForCampaign()})
     */
    private function countCampaignClicks(int $campaignId): ?int
    {
        try {
            // page_hits.source_id holds the campaign EVENT id, never the
            // campaign id — using $campaignId directly returned the clicks of
            // an unrelated event whose id merely matched numerically. Resolve
            // through campaign_lead_event_log, exactly as the Mautic core does.
            $hitsTable     = $this->prefix.'page_hits';
            $eventLogTable = $this->prefix.'campaign_lead_event_log';

            return (int) $this->connection->fetchOne(
                "SELECT COUNT(DISTINCT ph.id)
                 FROM {$hitsTable} ph
                 INNER JOIN {$eventLogTable} clel
                   ON ph.source_id = clel.event_id
                  AND ph.lead_id = clel.lead_id
                 WHERE ph.source = 'campaign.event'
                   AND clel.campaign_id = :cid",
                ['cid' => $campaignId],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('EwebSaasBundle: campaign clicks unmeasurable', [
                'campaignId' => $campaignId,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function envOrNull(string $key): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return is_string($v) && '' !== $v ? $v : null;
    }
}
