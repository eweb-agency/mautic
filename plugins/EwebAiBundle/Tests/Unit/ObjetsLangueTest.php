<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Les objets proposés suivent la langue de l'utilisateur, pas la valeur
 * par défaut du champ « Langue ».
 *
 * Le défaut (constaté en production le 27/11/2026 sur eweb-agency) : sur un
 * nouvel e-mail, le champ « Langue » vaut « anglais » (langue par défaut de
 * l'espace) alors que l'interface est en français ; le corps est le gabarit
 * « Hello World ». La bulle envoyait « English » et les cinq objets
 * sortaient en anglais.
 *
 * Côté navigateur, le test EXÉCUTE ai-copilot.js dans Node ; côté serveur,
 * il lit la consigne réellement envoyée au modèle.
 */
final class ObjetsLangueTest extends TestCase
{
    private const HARNAIS = <<<'JS'
        const fs = require('fs');
        const vm = require('vm');
        const [fichier, brut] = process.argv.slice(-2);
        const sc = JSON.parse(brut);

        // Le champ « Langue » tel que le formulaire le livre, puis ce que
        // l'utilisateur en fait.
        const attrs = {};
        let valeur = sc.arrivee;
        const champLangue = {
          length: 1,
          val: function () { return valeur; },
          attr: function (nom, v) { if (undefined === v) { return attrs[nom]; } attrs[nom] = v; return champLangue; },
          is: function (sel) { return '[data-sendly-langue-arrivee]' === sel && undefined !== attrs['data-sendly-langue-arrivee']; },
        };
        const vide = { length: 0, val: function () { return ''; }, attr: function () { return undefined; }, text: function () { return ''; }, after: function () {}, is: function () { return false; } };
        const objet = { length: 1, val: function () { return ''; }, after: function () {}, attr: function () { return undefined; } };
        const formulaire = { length: 1, attr: function (nom) { return 'action' === nom ? sc.action : undefined; } };
        const pretes = [];
        const mQuery = function (sel) {
          if ('function' === typeof sel) { pretes.push(sel); return; }
          if ('#emailform_language' === sel) { return champLangue; }
          if ('#emailform_subject' === sel) { return objet; }
          if ('form[name="emailform"]' === sel) { return formulaire; }
          return vide;
        };
        mQuery.ajax = function () { return { done: function () { return this; }, fail: function () { return this; } }; };
        const element = function () { return { setAttribute: function () {}, appendChild: function () {}, style: {}, classList: { add: function () {}, remove: function () {} } }; };
        const document = {
          documentElement: { lang: sc.interface, clientWidth: 1440 },
          head: { appendChild: function () {} },
          getElementById: function () { return null; },
          createElement: element,
          addEventListener: function () {},
        };
        const window = { SendlyAiConfig: { enabled: true, endpoint: '/s/ai/generate' }, addEventListener: function () {}, innerHeight: 800 };
        const Mautic = { translate: function (k) { return k; } };
        vm.runInNewContext(fs.readFileSync(fichier, 'utf8'), { window: window, document: document, mQuery: mQuery, Mautic: Mautic, console: console, setTimeout: setTimeout });

        // Le formulaire arrive (DOM prêt) : le bouton se pose, la langue
        // d'arrivée est notée.
        pretes.forEach(function (f) { f(); });
        if (sc.choix) { valeur = sc.choix; }
        process.stdout.write(JSON.stringify(window.SendlyAiObjets.langues()));
        JS;

    /**
     * @param array<string, string> $scenario
     *
     * @return array<string, string>
     */
    private function langues(array $scenario): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ('' === $node) {
            if ('true' === getenv('GITHUB_ACTIONS')) {
                self::fail('Node est introuvable dans le job PHPUnit : ce test doit tourner en CI.');
            }
            self::markTestSkipped('Node absent sur ce poste.');
        }

        $fichier = __DIR__.'/../../Assets/js/ai-copilot.js';
        $proc    = proc_open(
            [$node, '-e', self::HARNAIS, '--', $fichier, json_encode($scenario, JSON_THROW_ON_ERROR)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        $sortie = (string) stream_get_contents($pipes[1]);
        $erreur = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), 'le harnais Node a échoué : '.$erreur);

        $r = json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($r);

        return $r;
    }

    public function testNouvelEMailLaLangueParDefautNEstPasUnChoix(): void
    {
        $r = $this->langues(['arrivee' => 'en', 'interface' => 'fr', 'action' => '/s/emails/new']);

        self::assertSame('', $r['lang'], 'la valeur par défaut du champ ne doit pas partir comme un choix');
        self::assertSame('French', $r['uiLang']);
    }

    public function testUneLangueChoisieSurUnNouvelEMailEstSuivie(): void
    {
        $r = $this->langues(['arrivee' => 'en', 'interface' => 'fr', 'action' => '/s/emails/new', 'choix' => 'de']);

        self::assertSame('German', $r['lang']);
    }

    public function testUnEMailEnregistreGardeSaLangue(): void
    {
        $r = $this->langues(['arrivee' => 'en', 'interface' => 'fr', 'action' => '/s/emails/edit/12']);

        self::assertSame('English', $r['lang']);
    }

    /**
     * @param array<string, string> $params
     */
    private function consigneObjets(array $params): string
    {
        $prec                         = $_ENV['SENDLY_ANTHROPIC_KEY'] ?? null;
        $_ENV['SENDLY_ANTHROPIC_KEY'] = 'test-key';
        $envoye                       = [];
        $client                       = $this->createMock(Client::class);
        $client->method('request')->willReturnCallback(
            function (string $m, string $u, array $o) use (&$envoye): Response {
                $envoye = $o['json'] ?? [];

                return new Response(200, [], json_encode(['content' => [['type' => 'text', 'text' => "Un\nDeux\nTrois"]]], JSON_THROW_ON_ERROR));
            }
        );
        try {
            (new AiCopilotService($client, new NullLogger()))->suggestSubjects($params + ['content' => '<mj-text>Hello World!</mj-text>', 'count' => 3]);
        } finally {
            if (null === $prec) {
                unset($_ENV['SENDLY_ANTHROPIC_KEY']);
            } else {
                $_ENV['SENDLY_ANTHROPIC_KEY'] = $prec;
            }
        }

        return (string) ($envoye['system'][0]['text'] ?? '');
    }

    public function testSansChoixLeRepliEstLaLangueDeLInterface(): void
    {
        $consigne = $this->consigneObjets(['lang' => '', 'uiLang' => 'French']);

        self::assertStringContainsString('language the email body is actually written in', $consigne);
        self::assertStringContainsString('"Hello World"), write them in French.', $consigne);
        self::assertStringNotContainsString('the same language as the email body', $consigne, 'l’ancien repli suivait le gabarit anglais');
    }

    public function testUneLangueChoisieDevientLeRepli(): void
    {
        self::assertStringContainsString('write them in German.', $this->consigneObjets(['lang' => 'German', 'uiLang' => 'French']));
    }

    public function testSansRienLeRepliEstLeFrancais(): void
    {
        self::assertStringContainsString('write them in French.', $this->consigneObjets([]));
    }
}
