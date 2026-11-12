<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Le contrat avec l'API des modèles, après la migration du 11/11/2026.
 *
 * POURQUOI CE FICHIER. `claude-sonnet-4-5`, le cerveau du panneau, est retiré
 * le 30/11/2026. Ses successeurs ne se pilotent pas pareil, et chaque écart
 * se paie d'un 400 sur TOUS les messages du panneau, invisible en local
 * (aucune clé d'API sur le poste de développement) :
 *  - Sonnet 5.5 refuse l'outil imposé : `auto` + consigne + second essai ;
 *  - Haiku 5.5 l'accepte (et saute alors sa réflexion, plus rapide) ;
 *  - la réflexion compte dans `max_tokens` : une réponse coupée doit être
 *    refusée, pas réinjectée à moitié dans un e-mail ;
 *  - un refus de sécurité arrive en HTTP 200 avec `stop_reason: refusal`.
 */
final class AiCopilotModelesTest extends TestCase
{
    /** @var list<array<string, mixed>> corps JSON de chaque requête envoyée */
    private array $sent = [];

    /** @var array<string, string|null> */
    private array $previousEnv = [];

    private const ENV = ['SENDLY_ANTHROPIC_KEY', 'SENDLY_ANTHROPIC_MODEL', 'SENDLY_ANTHROPIC_MODEL_ASSIST', 'SENDLY_ANTHROPIC_MODEL_SEGMENT'];

    protected function setUp(): void
    {
        foreach (self::ENV as $key) {
            $this->previousEnv[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key]);
        }
        $_ENV['SENDLY_ANTHROPIC_KEY'] = 'test-key';
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if (null === $value) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $value;
            }
        }
    }

    /**
     * Client qui rejoue les réponses dans l'ordre (la dernière se répète).
     *
     * @param list<Response|\Throwable> $responses
     */
    private function service(array $responses): AiCopilotService
    {
        $this->sent = [];

        $client = $this->createMock(Client::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use (&$responses): Response {
                $this->sent[] = $options['json'] ?? [];
                $next         = count($responses) > 1 ? array_shift($responses) : $responses[0];
                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next;
            }
        );

        return new AiCopilotService($client, new NullLogger(), 0);
    }

    /**
     * @param list<array<string, mixed>> $content
     */
    private static function reply(array $content, string $stop = 'end_turn', int $status = 200): Response
    {
        return new Response($status, [], json_encode(['content' => $content, 'stop_reason' => $stop], JSON_THROW_ON_ERROR));
    }

    private static function assistTool(): Response
    {
        return self::reply([
            // Bloc de réflexion AVANT l'outil : lu par type, jamais par position.
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'x'],
            ['type' => 'tool_use', 'name' => 'repondre_et_agir', 'input' => ['answer' => 'Fait.', 'actions' => []]],
        ], 'tool_use');
    }

    /** @return array<string, mixed> */
    private static function assistParams(): array
    {
        return ['question' => 'remplis le nom', 'actions' => ['fill_field'], 'context' => 'Form fields: - name="x" (empty)'];
    }

    // ── Les modèles et leur pilotage ──────────────────────────────────────

    public function testLeCerveauDuPanneauEstSonnet55SansOutilImpose(): void
    {
        $out = $this->service([self::assistTool()])->assist(self::assistParams());

        self::assertSame('Fait.', $out['answer']);
        self::assertSame('claude-sonnet-5-5', $this->sent[0]['model']);
        // Un `tool_choice` de type `tool` vaut un 400 sur ce modèle.
        self::assertSame(['type' => 'auto'], $this->sent[0]['tool_choice']);
        self::assertSame(['effort' => 'low'], $this->sent[0]['output_config']);
    }

    public function testLesMainsSontHaiku55AvecOutilImpose(): void
    {
        $this->service([self::reply([
            ['type' => 'tool_use', 'name' => 'emit_segment_filters', 'input' => ['filters' => [], 'name' => 'x']],
        ], 'tool_use')])->suggestSegmentFilters(['description' => 'les clients de Lyon', 'catalog' => 'lead.city|text|=']);

        self::assertSame('claude-haiku-5-5', $this->sent[0]['model']);
        self::assertSame(['type' => 'tool', 'name' => 'emit_segment_filters'], $this->sent[0]['tool_choice']);
        self::assertStringNotContainsString('ALWAYS answer by calling', $this->sent[0]['system'][0]['text']);
    }

    public function testLaRedactionPartSurHaiku55AvecDeLaPlacePourLaReflexion(): void
    {
        $this->service([self::reply([['type' => 'text', 'text' => '<p>Bonjour</p>']])])
            ->generate('translate', ['content' => '<p>Hello</p>', 'language' => 'fr']);

        self::assertSame('claude-haiku-5-5', $this->sent[0]['model']);
        self::assertArrayNotHasKey('tools', $this->sent[0]);
        // 2048 coupait déjà une traduction complète ; la réflexion compte aussi.
        self::assertGreaterThanOrEqual(6000, $this->sent[0]['max_tokens']);
    }

    public function testUnAncienModeleImposeParVariableNeRecoitPasDEffort(): void
    {
        // `effort` renvoie une erreur sur Haiku 4.5 et Sonnet 4.5.
        $_ENV['SENDLY_ANTHROPIC_MODEL_ASSIST'] = 'claude-haiku-4-5';

        $this->service([self::assistTool()])->assist(self::assistParams());

        self::assertSame('claude-haiku-4-5', $this->sent[0]['model']);
        self::assertArrayNotHasKey('output_config', $this->sent[0]);
        self::assertSame(['type' => 'tool', 'name' => 'repondre_et_agir'], $this->sent[0]['tool_choice']);
    }

    public function testLaConsigneEstMiseEnCache(): void
    {
        $this->service([self::assistTool()])->assist(self::assistParams());

        $system = $this->sent[0]['system'];
        self::assertCount(1, $system);
        self::assertSame('text', $system[0]['type']);
        self::assertSame(['type' => 'ephemeral'], $system[0]['cache_control']);
        self::assertStringContainsString('OPERATOR, not a guide', $system[0]['text']);
    }

    // ── L'outil en `auto` n'est pas garanti ───────────────────────────────

    public function testSansAppelDOutilLeCerveauEstReinterrogeUneFois(): void
    {
        $out = $this->service([
            self::reply([['type' => 'text', 'text' => 'Je remplis le nom.']]),
            self::assistTool(),
        ])->assist(self::assistParams());

        self::assertCount(2, $this->sent);
        self::assertSame('Fait.', $out['answer']);
    }

    public function testDeuxReponsesSansOutilDonnentLeTexteSansAction(): void
    {
        $out = $this->service([self::reply([['type' => 'text', 'text' => 'Voici comment faire.']])])
            ->assist(self::assistParams());

        self::assertCount(2, $this->sent, 'un seul second essai, pas une boucle');
        self::assertSame('Voici comment faire.', $out['answer']);
        self::assertSame([], $out['actions']);
    }

    // ── Réponses coupées, refusées, surcharges ────────────────────────────

    public function testUneReponseCoupeeEstRefuseeAuLieuDEtreInjectee(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('truncated');

        $this->service([self::reply([['type' => 'text', 'text' => '<mjml><mj-body><mj-sect']], 'max_tokens')])
            ->generate('translate', ['content' => '<mjml></mjml>', 'language' => 'fr', 'format' => 'mjml']);
    }

    public function testUnRefusDeSecuriteEnHttp200EstUnEchec(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declined');

        $this->service([self::reply([], 'refusal')])->assist(self::assistParams());
    }

    public function testUneSurchargeEstRetenteeUneFois(): void
    {
        $out = $this->service([
            new Response(529, [], '{"type":"error","error":{"type":"overloaded_error"}}'),
            self::assistTool(),
        ])->assist(self::assistParams());

        self::assertCount(2, $this->sent);
        self::assertSame('Fait.', $out['answer']);
    }

    public function testDeuxSurchargesDeSuiteEchouentSansBoucler(): void
    {
        try {
            $this->service([new Response(429, ['retry-after' => '30'], '{}')])->assist(self::assistParams());
            self::fail('une double surcharge doit échouer');
        } catch (\RuntimeException) {
            self::assertCount(2, $this->sent);
        }
    }

    public function testUneErreurDeFormeNEstPasRetentee(): void
    {
        try {
            $this->service([new Response(400, [], '{"type":"error","error":{"type":"invalid_request_error","message":"tool_choice"}}')])
                ->assist(self::assistParams());
            self::fail('un 400 doit échouer');
        } catch (\RuntimeException) {
            self::assertCount(1, $this->sent, 'rejouer une requête mal formée ne sert à rien');
        }
    }

    public function testUnePanneReseauEchoueProprement(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI request failed.');

        $this->service([new ConnectException('timeout', new Request('POST', 'https://api.anthropic.com/v1/messages'))])
            ->assist(self::assistParams());
    }
}
