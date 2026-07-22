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

    public function testEmailSeriesFillsMissingDaysWithZeros(): void
    {
        $today     = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        // Deux requêtes GROUP BY (envois puis ouvertures) : hier a eu des
        // envois et des ouvertures, aujourd'hui rien — le point d'aujourd'hui
        // doit exister à 0/0, pas manquer.
        $this->connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['d' => $yesterday, 'c' => '12']],
            [['d' => $yesterday, 'c' => '5']],
        );

        $series = $this->createAggregator(new ArrayAdapter())->getEmailSeries(7);

        $this->assertCount(7, $series, 'one point per day of the window');
        $byDate = array_column($series, null, 'date');
        $this->assertSame(12, $byDate[$yesterday]['sent']);
        $this->assertSame(5, $byDate[$yesterday]['opened']);
        $this->assertSame(0, $byDate[$today]['sent'], 'a day without activity plots as 0');
        $this->assertSame(0, $byDate[$today]['opened']);
    }

    public function testEmailSeriesRejectsUnknownWindows(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        // 13 n'est pas une fenêtre autorisée → retombe sur 30 jours.
        $series = $this->createAggregator(new ArrayAdapter())->getEmailSeries(13);

        $this->assertCount(30, $series);
    }

    public function testScheduledEmailsMapsRowsToTypedEntries(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['id' => '7', 'name' => 'Newsletter', 'publish_up' => '2026-08-01 09:00:00'],
        ]);

        $emails = $this->createAggregator(new ArrayAdapter())->getScheduledEmails();

        $this->assertSame(
            [['id' => 7, 'name' => 'Newsletter', 'publishUp' => '2026-08-01 09:00:00']],
            $emails,
        );
    }

    public function testSegmentsAndFormsMapRowsToTypedEntries(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['id' => '3', 'name' => 'Clients', 'is_published' => '1', 'contacts' => '42']],
            [['id' => '9', 'name' => 'Contact', 'is_published' => '0', 'submissions' => '7']],
        );
        $aggregator = $this->createAggregator(new ArrayAdapter());

        $this->assertSame(
            [['id' => 3, 'name' => 'Clients', 'isPublished' => true, 'contacts' => 42]],
            $aggregator->getSegments(),
        );
        $this->assertSame(
            [['id' => 9, 'name' => 'Contact', 'isPublished' => false, 'submissions' => 7]],
            $aggregator->getForms(),
        );
    }

    public function testInvalidateCacheClearsSegmentsAndFormsKeys(): void
    {
        $cache = new ArrayAdapter();
        foreach (['segments.list', 'forms.list'] as $key) {
            $cache->get($key, static fn (): array => ['stale' => true]);
        }

        $this->createAggregator($cache)->invalidateCache();

        foreach (['segments.list', 'forms.list'] as $key) {
            $this->assertFalse(
                $cache->getItem($key)->isHit(),
                sprintf('%s must be cleared by a manual refresh', $key),
            );
        }
    }

    public function testInvalidateCacheClearsScheduledEmailsKey(): void
    {
        $cache = new ArrayAdapter();
        $cache->get('emails.scheduled.60', static fn (): array => ['stale' => true]);

        $this->createAggregator($cache)->invalidateCache();

        $this->assertFalse(
            $cache->getItem('emails.scheduled.60')->isHit(),
            'emails.scheduled.60 must be cleared by a manual refresh',
        );
    }

    public function testInvalidateCacheClearsEmailSeriesKeys(): void
    {
        $cache = new ArrayAdapter();
        foreach ([7, 30, 90] as $days) {
            $cache->get('emails.series.'.$days, static fn (): array => ['stale' => true]);
        }

        $this->createAggregator($cache)->invalidateCache();

        foreach ([7, 30, 90] as $days) {
            $this->assertFalse(
                $cache->getItem('emails.series.'.$days)->isHit(),
                sprintf('emails.series.%d must be cleared by a manual refresh', $days),
            );
        }
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
