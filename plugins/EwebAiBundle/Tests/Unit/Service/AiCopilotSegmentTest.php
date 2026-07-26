<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Ce que ces tests protègent, c'est la CONTRAINTE de sortie.
 *
 * Le modèle ne doit pas pouvoir répondre en prose : on lui impose un outil, ce
 * qui élimine d'un coup le texte libre, le JSON malformé et les clés
 * fantaisistes. Si `tools` ou `tool_choice` disparaissaient de la requête — un
 * refactoring distrait suffit — la fonctionnalité continuerait de « marcher »
 * la plupart du temps, puis échouerait au hasard sur une réponse bavarde. Un
 * défaut intermittent en production, invisible en développement.
 *
 * On vérifie aussi que le prompt embarque bien le catalogue de l'instance et
 * les jetons de date : sans eux, le modèle invente des champs plausibles, et
 * c'est le validateur qui devrait tout jeter.
 */
final class AiCopilotSegmentTest extends TestCase
{
    /** @var array<string, mixed> corps JSON réellement envoyé */
    private array $sent = [];

    private ?string $previousKey   = null;
    private ?string $previousModel = null;

    protected function setUp(): void
    {
        $this->previousKey   = $_ENV['SENDLY_ANTHROPIC_KEY'] ?? null;
        $this->previousModel = $_ENV['SENDLY_ANTHROPIC_MODEL_SEGMENT'] ?? null;

        $_ENV['SENDLY_ANTHROPIC_KEY'] = 'test-key';
        unset($_ENV['SENDLY_ANTHROPIC_MODEL_SEGMENT']);
    }

    protected function tearDown(): void
    {
        foreach (['SENDLY_ANTHROPIC_KEY' => $this->previousKey, 'SENDLY_ANTHROPIC_MODEL_SEGMENT' => $this->previousModel] as $key => $value) {
            if (null === $value) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    private function service(string $body, int $status = 200): AiCopilotService
    {
        $this->sent = [];

        $client = $this->createMock(Client::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use ($body, $status): Response {
                $this->sent = $options['json'] ?? [];

                return new Response($status, [], $body);
            }
        );

        return new AiCopilotService($client, new NullLogger());
    }

    /** Réponse Anthropic normale : un bloc d'usage d'outil. */
    private function toolResponse(array $filters): string
    {
        return json_encode([
            'content' => [[
                'type'  => 'tool_use',
                'name'  => 'emit_segment_filters',
                'input' => ['filters' => $filters],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function params(): array
    {
        return [
            'description' => 'les clients VIP inactifs',
            'catalog'     => "lead.city|text|=,!=\nlead.tags|tags|in,!in|VALUES:12=VIP",
            'date_tokens' => ['today', 'month_last'],
            'lang'        => 'French',
        ];
    }

    public function testForcesTheModelToAnswerThroughTheTool(): void
    {
        $this->service($this->toolResponse([['field' => 'city']]))
            ->suggestSegmentFilters($this->params());

        self::assertArrayHasKey('tools', $this->sent, 'aucun outil imposé : le modèle peut répondre en prose');
        self::assertSame('emit_segment_filters', $this->sent['tools'][0]['name']);
        self::assertSame(
            ['type' => 'tool', 'name' => 'emit_segment_filters'],
            $this->sent['tool_choice'] ?? null,
            'sans tool_choice, l’outil est optionnel pour le modèle'
        );
    }

    public function testTheSchemaForbidsUnknownKeysAndCapsTheFilterCount(): void
    {
        $this->service($this->toolResponse([]))->suggestSegmentFilters($this->params());

        $schema = $this->sent['tools'][0]['input_schema'];
        $items  = $schema['properties']['filters']['items'];

        self::assertFalse($items['additionalProperties']);
        self::assertSame(10, $schema['properties']['filters']['maxItems']);
        self::assertSame(['lead', 'behaviors'], $items['properties']['object']['enum']);
        self::assertSame(['and', 'or'], $items['properties']['glue']['enum']);
    }

    public function testSendsTheInstanceCatalogAndDateTokensInThePrompt(): void
    {
        $this->service($this->toolResponse([]))->suggestSegmentFilters($this->params());

        $system = $this->sent['system'];

        self::assertStringContainsString('lead.tags|tags|in,!in|VALUES:12=VIP', $system, 'le catalogue réel doit être transmis');
        self::assertStringContainsString('today, month_last', $system, 'les jetons de date doivent être transmis');
        self::assertStringContainsString('French', $system);
    }

    public function testPutsTheClientDescriptionInTheUserMessageNotTheSystemPrompt(): void
    {
        // La description est une donnée client, pas une consigne système. La
        // placer dans le prompt système lui donnerait autorité sur les règles.
        $this->service($this->toolResponse([]))->suggestSegmentFilters($this->params());

        self::assertStringNotContainsString('les clients VIP inactifs', $this->sent['system']);
        self::assertStringContainsString('les clients VIP inactifs', $this->sent['messages'][0]['content']);
    }

    public function testCollapsesAndBoundsTheDescription(): void
    {
        $this->service($this->toolResponse([]))->suggestSegmentFilters(
            ['description' => "trop\n\n  d'espaces   ".str_repeat('x', 3000)] + $this->params()
        );

        $content = $this->sent['messages'][0]['content'];

        self::assertStringContainsString("trop d'espaces", $content);
        self::assertLessThan(1100, mb_strlen($content), 'la description doit être bornée');
    }

    public function testUsesTheSegmentSpecificModelWhenConfigured(): void
    {
        $_ENV['SENDLY_ANTHROPIC_MODEL_SEGMENT'] = 'claude-opus-5';

        $this->service($this->toolResponse([]))->suggestSegmentFilters($this->params());

        self::assertSame('claude-opus-5', $this->sent['model']);
    }

    public function testReturnsTheFiltersFromTheToolBlock(): void
    {
        $out = $this->service($this->toolResponse([
            ['glue' => 'and', 'object' => 'lead', 'field' => 'city', 'operator' => '=', 'value' => 'Paris'],
        ]))->suggestSegmentFilters($this->params());

        self::assertCount(1, $out);
        self::assertSame('city', $out[0]['field']);
    }

    public function testFallsBackToFencedTextWhenTheModelIgnoresTheTool(): void
    {
        $body = json_encode([
            'content' => [['type' => 'text', 'text' => "```json\n{\"filters\":[{\"field\":\"city\"}]}\n```"]],
        ], JSON_THROW_ON_ERROR);

        $out = $this->service($body)->suggestSegmentFilters($this->params());

        self::assertCount(1, $out);
    }

    public function testDropsNonObjectEntriesAndCapsAtTenFilters(): void
    {
        $noise   = array_fill(0, 15, ['field' => 'city']);
        $noise[] = 'du texte';

        $out = $this->service($this->toolResponse($noise))->suggestSegmentFilters($this->params());

        self::assertCount(10, $out);
    }

    public function testRefusesAPayloadWithoutFilters(): void
    {
        $body = json_encode([
            'content' => [['type' => 'tool_use', 'name' => 'emit_segment_filters', 'input' => ['autre' => 1]]],
        ], JSON_THROW_ON_ERROR);

        $this->expectException(\RuntimeException::class);

        $this->service($body)->suggestSegmentFilters($this->params());
    }

    public function testRefusesToCallTheApiWithoutAKey(): void
    {
        unset($_ENV['SENDLY_ANTHROPIC_KEY'], $_SERVER['SENDLY_ANTHROPIC_KEY']);

        if (false !== getenv('SENDLY_ANTHROPIC_KEY')) {
            self::markTestSkipped('clé présente dans l’environnement du processus');
        }

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('request');

        $service = new AiCopilotService($client, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $service->suggestSegmentFilters($this->params());
    }
}
