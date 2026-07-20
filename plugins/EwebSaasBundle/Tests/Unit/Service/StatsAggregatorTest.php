<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\EwebSaasBundle\Service\ContactLimitChecker;
use MauticPlugin\EwebSaasBundle\Service\StatsAggregator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Cache invalidation contract.
 *
 * The dashboard's "Refresh" button calls SaasStatsController::refreshAction(),
 * which calls invalidateCache(). Anything that method forgets to clear is a
 * KPI that stays stale while the UI reports the data as freshly fetched — a
 * lie the user has no way to detect.
 *
 * Three caches feed the payload and they are NOT the same pool:
 *   - 'stats.full'            (this service, TTL 60s)
 *   - 'campaigns.recent.<n>'  (this service, TTL 60s, one key per limit)
 *   - the contact count       (ContactLimitChecker, TTL 300s)
 */
class StatsAggregatorTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    public function testInvalidateCacheClearsTheFullStatsKey(): void
    {
        $cache = new ArrayAdapter();
        $cache->get('stats.full', static fn (): array => ['stale' => true]);

        $this->createAggregator($cache)->invalidateCache();

        $this->assertFalse(
            $cache->getItem('stats.full')->isHit(),
            'stats.full must be cleared by a manual refresh',
        );
    }

    public function testInvalidateCacheClearsEveryCampaignKeyItCanProduce(): void
    {
        $cache = new ArrayAdapter();
        // getRecentCampaigns() clamps `limit` to [1, 50], so these are the
        // boundaries and one interior value of the reachable key space.
        foreach ([1, 5, 50] as $limit) {
            $cache->get('campaigns.recent.'.$limit, static fn (): array => ['stale' => true]);
        }

        $this->createAggregator($cache)->invalidateCache();

        foreach ([1, 5, 50] as $limit) {
            $this->assertFalse(
                $cache->getItem('campaigns.recent.'.$limit)->isHit(),
                sprintf('campaigns.recent.%d must be cleared by a manual refresh', $limit),
            );
        }
    }

    /**
     * The regression this class was written for.
     *
     * countContacts() delegates to ContactLimitChecker, so the contact count
     * sits in a SEPARATE pool with a 5x longer TTL. Clearing only our own keys
     * meant `quotas.contacts.used` and `totals.contacts` survived a refresh.
     */
    public function testInvalidateCacheAlsoClearsTheContactCount(): void
    {
        $checkerCache = new ArrayAdapter();
        $checker      = $this->createChecker($checkerCache);

        // Prime the checker's cache: one DB read, then it serves from cache.
        $this->connection->method('fetchOne')->willReturn(42);
        $this->assertSame(42, $checker->getCurrentContactCount());

        $aggregator = new StatsAggregator(
            $this->connection,
            $checker,
            new NullLogger(),
            new ArrayAdapter(),
        );
        $aggregator->invalidateCache();

        $this->assertFalse(
            $checkerCache->getItem('eweb_saas_contact_count')->isHit(),
            'the contact count must be re-read after a manual refresh, '
            .'otherwise the quota KPI stays stale for up to its own 300s TTL',
        );
    }

    private function createChecker(ArrayAdapter $cache): ContactLimitChecker
    {
        return new ContactLimitChecker($this->connection, new NullLogger(), $cache);
    }

    private function createAggregator(ArrayAdapter $cache): StatsAggregator
    {
        return new StatsAggregator(
            $this->connection,
            $this->createChecker(new ArrayAdapter()),
            new NullLogger(),
            $cache,
        );
    }
}
