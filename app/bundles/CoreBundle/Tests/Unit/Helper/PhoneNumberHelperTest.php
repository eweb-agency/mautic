<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit\Helper;

use Mautic\CoreBundle\Helper\PhoneNumberHelper;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

class PhoneNumberHelperTest extends TestCase
{
    public function testNationalNumberIsReadAsFrench(): void
    {
        Assert::assertSame('+33612345678', (new PhoneNumberHelper())->format('0612345678'));
    }

    public function testInternationalNumberKeepsItsCountry(): void
    {
        Assert::assertSame('+14155552671', (new PhoneNumberHelper())->format('+14155552671'));
    }

    public function testNumberListFindsAContactStoredInNationalFormat(): void
    {
        $list = (new PhoneNumberHelper())->getFormattedNumberList('+33612345678');

        Assert::assertContains('0612345678', $list);
        Assert::assertContains('+33612345678', $list);
    }
}
