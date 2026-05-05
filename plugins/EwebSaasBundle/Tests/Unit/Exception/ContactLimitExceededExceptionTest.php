<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Exception;

use MauticPlugin\EwebSaasBundle\Exception\ContactLimitExceededException;
use PHPUnit\Framework\TestCase;

class ContactLimitExceededExceptionTest extends TestCase
{
    public function testExceptionCarriesCountAndLimit(): void
    {
        $exception = new ContactLimitExceededException(150, 100);

        $this->assertSame(150, $exception->getCurrentCount());
        $this->assertSame(100, $exception->getMaxLimit());
    }

    public function testMessageContainsCountsFormatted(): void
    {
        $exception = new ContactLimitExceededException(50, 50);

        $this->assertStringContainsString('50/50', $exception->getMessage());
        $this->assertStringContainsString('Limite de contacts atteinte', $exception->getMessage());
    }

    public function testExceptionExtendsRuntimeException(): void
    {
        $exception = new ContactLimitExceededException(1, 1);

        $this->assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testGetUserMessageContainsPortalUpgradeHint(): void
    {
        $exception = new ContactLimitExceededException(10, 5);

        $userMessage = $exception->getUserMessage();
        $this->assertStringContainsString('10/5', $userMessage);
        $this->assertStringContainsString('Limite de contacts atteinte', $userMessage);
        $this->assertStringContainsString('portail', $userMessage);
        $this->assertStringNotContainsString('<a href=', $userMessage);
    }
}
