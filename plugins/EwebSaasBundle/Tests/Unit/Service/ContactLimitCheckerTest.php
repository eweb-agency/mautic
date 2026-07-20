<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\EwebSaasBundle\Exception\ContactLimitExceededException;
use MauticPlugin\EwebSaasBundle\Service\ContactLimitChecker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class ContactLimitCheckerTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    private function createChecker(int $limit): ContactLimitChecker
    {
        // Set the env var before constructing the checker.
        $_ENV['MAUTIC_CONTACT_MAX_LIMIT'] = (string) $limit;

        return new ContactLimitChecker(
            $this->connection,
            new NullLogger(),
            new ArrayAdapter(),
        );
    }

    protected function tearDown(): void
    {
        unset($_ENV['MAUTIC_CONTACT_MAX_LIMIT']);
    }

    /**
     * Pins the pricing definition (owner decision, 2026-07-20): the quota
     * counts IDENTIFIED contacts only. An unfiltered COUNT(*) let anonymous
     * tracked visitors exhaust a tenant's plan on traffic alone. Reverting
     * the predicate turns this red.
     */
    public function testCountOnlyIncludesIdentifiedContacts(): void
    {
        $capturedSql = null;
        $this->connection->method('fetchOne')
            ->willReturnCallback(function (string $sql) use (&$capturedSql): int {
                $capturedSql = $sql;

                return 42;
            });

        $checker = $this->createChecker(100);
        $this->assertSame(42, $checker->getCurrentContactCount());
        $this->assertStringContainsString('date_identified IS NOT NULL', (string) $capturedSql);
    }

    /**
     * The literal cache key is what every invalidateCache() call in the
     * bundle deletes — StatsAggregator clears it through the checker on
     * manual refresh. A silent rename would orphan them all.
     */
    public function testCacheKeyIsStable(): void
    {
        $cache                            = new ArrayAdapter();
        $_ENV['MAUTIC_CONTACT_MAX_LIMIT'] = '100';
        $checker                          = new ContactLimitChecker($this->connection, new NullLogger(), $cache);

        $this->connection->method('fetchOne')->willReturn(7);
        $checker->getCurrentContactCount();

        $this->assertTrue($cache->getItem('eweb_saas_contact_count')->isHit());
    }

    public function testIsLimitEnabledReturnsFalseWhenZero(): void
    {
        $checker = $this->createChecker(0);
        $this->assertFalse($checker->isLimitEnabled());
    }

    public function testIsLimitEnabledReturnsTrueWhenPositive(): void
    {
        $checker = $this->createChecker(100);
        $this->assertTrue($checker->isLimitEnabled());
    }

    public function testGetMaxLimitReturnsConfiguredValue(): void
    {
        $checker = $this->createChecker(42);
        $this->assertSame(42, $checker->getMaxLimit());
    }

    public function testCanCreateContactReturnsTrueWhenNoLimit(): void
    {
        $checker = $this->createChecker(0);
        // Should not query the DB at all.
        $this->connection->expects($this->never())->method('fetchOne');
        $this->assertTrue($checker->canCreateContact());
    }

    public function testCanCreateContactReturnsTrueWhenBelowLimit(): void
    {
        $this->connection->method('fetchOne')->willReturn('5');
        $checker = $this->createChecker(10);
        $this->assertTrue($checker->canCreateContact());
    }

    public function testCanCreateContactReturnsFalseWhenAtLimit(): void
    {
        $this->connection->method('fetchOne')->willReturn('10');
        $checker = $this->createChecker(10);
        $this->assertFalse($checker->canCreateContact());
    }

    public function testCanCreateContactReturnsFalseWhenAboveLimit(): void
    {
        $this->connection->method('fetchOne')->willReturn('15');
        $checker = $this->createChecker(10);
        $this->assertFalse($checker->canCreateContact());
    }

    public function testAssertCanCreateContactDoesNotThrowWhenNoLimit(): void
    {
        $checker = $this->createChecker(0);
        $this->connection->expects($this->never())->method('fetchOne');
        $checker->assertCanCreateContact(); // Should not throw.
        $this->addToAssertionCount(1);
    }

    public function testAssertCanCreateContactDoesNotThrowWhenBelowLimit(): void
    {
        $this->connection->method('fetchOne')->willReturn('1');
        $checker = $this->createChecker(2);
        $checker->assertCanCreateContact(); // Should not throw.
        $this->addToAssertionCount(1);
    }

    public function testAssertCanCreateContactThrowsWhenAtLimit(): void
    {
        $this->connection->method('fetchOne')->willReturn('2');
        $checker = $this->createChecker(2);

        $this->expectException(ContactLimitExceededException::class);
        $checker->assertCanCreateContact();
    }

    public function testAssertCanCreateContactThrowsWithCorrectCounts(): void
    {
        $this->connection->method('fetchOne')->willReturn('5');
        $checker = $this->createChecker(3);

        try {
            $checker->assertCanCreateContact();
            $this->fail('Expected ContactLimitExceededException');
        } catch (ContactLimitExceededException $e) {
            $this->assertSame(5, $e->getCurrentCount());
            $this->assertSame(3, $e->getMaxLimit());
            $this->assertStringContainsString('5/3', $e->getMessage());
        }
    }

    public function testInvalidateCacheForcesRequery(): void
    {
        // First call returns 1, second returns 5.
        $this->connection->method('fetchOne')
            ->willReturnOnConsecutiveCalls('1', '5');

        $checker = $this->createChecker(10);

        // First call – queries DB, gets 1.
        $this->assertSame(1, $checker->getCurrentContactCount());

        // Invalidate the cache.
        $checker->invalidateCache();

        // Second call – queries DB again, gets 5.
        $this->assertSame(5, $checker->getCurrentContactCount());
    }

    public function testAssertCanCreateContactThrowsWithUserMessage(): void
    {
        $this->connection->method('fetchOne')->willReturn('5');
        $checker = $this->createChecker(3);

        try {
            $checker->assertCanCreateContact();
            $this->fail('Expected ContactLimitExceededException');
        } catch (ContactLimitExceededException $e) {
            $this->assertStringContainsString('portail', $e->getUserMessage());
            $this->assertStringContainsString('5/3', $e->getUserMessage());
        }
    }
}
