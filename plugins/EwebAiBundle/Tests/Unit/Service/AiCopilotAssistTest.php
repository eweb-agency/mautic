<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * L'assistant d'aide — ce que ces tests protègent :
 *  - la MARQUE BLANCHE : une réponse qui nomme le moteur est une fuite
 *    publiée directement chez le client (règle B-02) — le filet réécrit
 *    toute échappée, quelle que soit la casse ;
 *  - l'HISTORIQUE est borné et re-validé : tours excédentaires écartés,
 *    alternance imposée (l'API refuse deux tours consécutifs du même rôle,
 *    et le premier doit venir de l'utilisateur) ;
 *  - la CONSIGNE embarque la langue demandée et le sujet borné à Sendly ;
 *  - une question vide est refusée AVANT tout appel réseau.
 */
final class AiCopilotAssistTest extends TestCase
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

    private function service(string $answer, int $status = 200): AiCopilotService
    {
        $this->sent = [];

        $body = json_encode([
            'content' => [['type' => 'text', 'text' => $answer]],
        ], JSON_THROW_ON_ERROR);

        $client = $this->createMock(Client::class);
        $client->method('request')->willReturnCallback(
            function (string $method, string $uri, array $options) use ($body, $status): Response {
                $this->sent = $options['json'] ?? [];

                return new Response($status, [], $body);
            }
        );

        return new AiCopilotService($client, new NullLogger());
    }

    public function testLaMarqueDuMoteurEstReecriteQuelleQueSoitLaCasse(): void
    {
        $answer = $this->service('Dans Mautic, ouvrez « Segments ». MAUTIC gère cela nativement.')
            ->assist(['question' => 'Comment créer un segment ?']);

        self::assertStringNotContainsStringIgnoringCase('mautic', $answer);
        self::assertSame('Dans Sendly, ouvrez « Segments ». Sendly gère cela nativement.', $answer);
    }

    public function testLHistoriqueEstBorneEtLaQuestionCouranteFermeLaConversation(): void
    {
        $history = [];
        for ($i = 1; $i <= 10; ++$i) {
            $history[] = ['role' => 0 === $i % 2 ? 'assistant' : 'user', 'content' => "tour $i"];
        }

        $this->service('ok')->assist(['question' => 'ma question', 'history' => $history]);

        $messages = $this->sent['messages'];
        // 6 tours d'historique au plus + la question courante.
        self::assertLessThanOrEqual(7, count($messages));
        self::assertSame('user', $messages[0]['role'], 'le premier tour doit venir de l’utilisateur');
        self::assertSame(['role' => 'user', 'content' => 'ma question'], end($messages));
        // Alternance stricte sur toute la conversation envoyée.
        for ($i = 1; $i < count($messages); ++$i) {
            self::assertNotSame($messages[$i - 1]['role'], $messages[$i]['role'], "deux tours consécutifs du même rôle (index $i)");
        }
    }

    public function testUnHistoriqueMalFormeEstAssaini(): void
    {
        $this->service('ok')->assist([
            'question' => 'q',
            'history'  => [
                ['role' => 'assistant', 'content' => 'je commence ? non.'],
                'pas un tableau',
                ['role' => 'system', 'content' => 'rôle inconnu → user'],
                ['role' => 'user', 'content' => '   '],
                ['role' => 'assistant', 'content' => 'réponse'],
                // Deux tours assistant CONSÉCUTIFS (cas réel : le client
                // filtre ses tours d'erreur avant l'envoi) : le second doit
                // être écarté, l'API refuse la répétition de rôle.
                ['role' => 'assistant', 'content' => 're-réponse'],
            ],
        ]);

        $messages = $this->sent['messages'];
        self::assertSame(
            [
                ['role' => 'user', 'content' => 'rôle inconnu → user'],
                ['role' => 'assistant', 'content' => 'réponse'],
                ['role' => 'user', 'content' => 'q'],
            ],
            $messages
        );
    }

    public function testLaConsignePorteLaLangueEtLaMarque(): void
    {
        $this->service('ok')->assist(['question' => 'q', 'lang' => 'German']);

        $system = (string) $this->sent['system'];
        self::assertStringContainsString('Answer in German', $system);
        self::assertStringContainsString('called Sendly and ONLY Sendly', $system);
    }

    public function testLeMarkdownResiduelEstAplatiEnTexteBrut(): void
    {
        // Constaté à la première vérification en prod : le panneau rend du
        // texte brut, un « # Titre » et des « **gras** » s'affichaient tels
        // quels malgré la consigne. Le filet aplatit ; les listes numérotées
        // et les tirets, lisibles en brut, restent intacts.
        $answer = $this->service("# Créer un segment\n\n1. Allez dans **Segments → Nouveau**\n2. Cliquez sur `Créer`\n- astuce : 2 ** seuls restent")
            ->assist(['question' => 'q']);

        self::assertSame(
            "Créer un segment\n\n1. Allez dans Segments → Nouveau\n2. Cliquez sur Créer\n- astuce : 2 ** seuls restent",
            $answer
        );
    }

    public function testUneQuestionVideEstRefuseeAvantToutAppel(): void
    {
        $service = $this->service('jamais appelé');

        $this->expectException(\InvalidArgumentException::class);

        try {
            $service->assist(['question' => '   ']);
        } finally {
            self::assertSame([], $this->sent, 'aucun appel réseau ne doit partir');
        }
    }
}
