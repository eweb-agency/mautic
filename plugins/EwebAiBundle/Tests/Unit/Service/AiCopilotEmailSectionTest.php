<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Lot E5 — la tuile « Assistant IA » de l'éditeur d'e-mails demande une
 * SECTION en place, jamais un document, et retouche des FRAGMENTS :
 *  - surface=email-section + mjml → exactement une <mj-section>, sans
 *    <mjml> ni <mj-body> ;
 *  - surface=email-section + html → une rangée table, sans <html>/<body> ;
 *  - fragment=true sur improve/translate → le fragment revient tel quel, sans
 *    ré-enveloppe (la règle « document MJML complet » d'origine disparaît) ;
 *  - sans surface, le corps complet d'e-mail d'origine est inchangé.
 */
final class AiCopilotEmailSectionTest extends TestCase
{
    /** @var array<string, mixed> corps JSON réellement envoyé */
    private array $sent = [];

    private ?string $previousKey = null;

    protected function setUp(): void
    {
        $this->previousKey            = $_ENV['SENDLY_ANTHROPIC_KEY'] ?? null;
        $_ENV['SENDLY_ANTHROPIC_KEY'] = 'test-key';
    }

    protected function tearDown(): void
    {
        if (null === $this->previousKey) {
            unset($_ENV['SENDLY_ANTHROPIC_KEY']);
        } else {
            $_ENV['SENDLY_ANTHROPIC_KEY'] = $this->previousKey;
        }
    }

    private function service(): AiCopilotService
    {
        $this->sent = [];
        $body       = json_encode(['content' => [['type' => 'text', 'text' => '<mj-section></mj-section>']]], JSON_THROW_ON_ERROR);

        $client = $this->createMock(Client::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use ($body): Response {
                $this->sent = $options['json'] ?? [];

                return new Response(200, [], $body);
            }
        );

        return new AiCopilotService($client, new NullLogger());
    }

    public function testLaSectionMjmlEstDemandeeSansEnveloppe(): void
    {
        $this->service()->generate('generate', ['instruction' => 'Un bloc bienvenue', 'format' => 'mjml', 'surface' => 'email-section']);

        $system = (string) ($this->sent['system'] ?? '');
        self::assertStringContainsString('ONE self-contained SECTION of a marketing email', $system);
        self::assertStringContainsString('exactly one <mj-section>', $system);
        self::assertStringContainsString('Do NOT wrap it in <mjml>', $system);
        self::assertStringNotContainsString('starts with <mjml>', $system, 'la règle « document complet » ne s applique pas à une section');
    }

    public function testLaSectionHtmlEstUneRangeeTable(): void
    {
        $this->service()->generate('generate', ['instruction' => 'Un bloc bienvenue', 'format' => 'html', 'surface' => 'email-section']);

        $system = (string) ($this->sent['system'] ?? '');
        self::assertStringContainsString('exactly one table-based block', $system);
        self::assertStringContainsString('do not wrap it in <html>', $system);
    }

    public function testLeCorpsCompletDOrigineEstInchangeSansSurface(): void
    {
        $this->service()->generate('generate', ['instruction' => 'Un e-mail', 'format' => 'mjml']);

        $system = (string) ($this->sent['system'] ?? '');
        self::assertStringContainsString('Produce the BODY of a marketing email', $system);
        self::assertStringContainsString('starts with <mjml> and ends with </mjml>', $system);
    }

    public function testLaRetoucheDUnFragmentNeReenveloppePas(): void
    {
        $this->service()->generate('improve', ['content' => '<p>Bonjour</p>', 'format' => 'mjml', 'fragment' => true]);
        $improve = (string) ($this->sent['system'] ?? '');
        self::assertStringContainsString('The content is a FRAGMENT', $improve);
        self::assertStringNotContainsString('starts with <mjml>', $improve);

        $this->service()->generate('translate', ['content' => '<p>Bonjour</p>', 'lang' => 'anglais', 'format' => 'html', 'fragment' => true]);
        $translate = (string) ($this->sent['system'] ?? '');
        self::assertStringContainsString('The content is a FRAGMENT', $translate);

        // Sans fragment, la retouche garde ses règles de document (modale ✨).
        $this->service()->generate('improve', ['content' => '<mjml></mjml>', 'format' => 'mjml']);
        $document = (string) ($this->sent['system'] ?? '');
        self::assertStringContainsString('starts with <mjml> and ends with </mjml>', $document);
    }
}
