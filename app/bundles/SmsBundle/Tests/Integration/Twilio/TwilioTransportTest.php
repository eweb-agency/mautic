<?php

declare(strict_types=1);

namespace Mautic\SmsBundle\Tests\Integration\Twilio;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\SmsBundle\Integration\Twilio\Configuration;
use Mautic\SmsBundle\Integration\Twilio\TwilioTransport;
use Monolog\Logger;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TwilioTransportTest extends TestCase
{
    private TwilioTransport $twilioTransport;

    /**
     * @var MockObject&Logger
     */
    private MockObject $logger;

    protected function setUp(): void
    {
        $this->logger      = $this->createMock(Logger::class);
        $integrationHelper = $this->createMock(IntegrationHelper::class);
        $configuration     = new Configuration($integrationHelper);

        $this->twilioTransport = new TwilioTransport($configuration, $this->logger);
    }

    public function testSendSMS(): void
    {
        $lead = new Lead();
        $lead->setMobile('123456');
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('mautic.sms.transport.twilio.not_configured');

        $this->twilioTransport->sendSms($lead, 'some_content');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function numberProvider(): iterable
    {
        yield 'mobile français sans indicatif' => ['0612345678', '+33612345678'];
        yield 'mobile français avec espaces' => ['06 12 34 56 78', '+33612345678'];
        yield 'numéro français international' => ['+33612345678', '+33612345678'];
        yield 'numéro étranger international' => ['+14155552671', '+14155552671'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('numberProvider')]
    public function testSanitizeNumber(string $number, string $expected): void
    {
        $reflection = new \ReflectionClass($this->twilioTransport::class);
        $method     = $reflection->getMethod('sanitizeNumber');
        $method->setAccessible(true);

        Assert::assertSame($expected, $method->invoke($this->twilioTransport, $number));
    }

    public function testCreatePayload(): void
    {
        $reflection = new \ReflectionClass($this->twilioTransport::class);
        $method     = $reflection->getMethod('createPayload');
        $method->setAccessible(true);

        $payload = ['messagingServiceSid' => 'MS1234', 'body' => 'some_content'];

        $result = $method->invokeArgs($this->twilioTransport, array_values($payload));
        Assert::assertSame($payload, $result);
    }
}
