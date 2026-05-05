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
