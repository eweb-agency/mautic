<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Dans l'éditeur, le CLIC sur la tuile « Sendly Copilot » ouvre l'invite.
 *
 * Le défaut (constaté en production le 27/11/2026) : le clic se câblait au
 * « load » de l'éditeur, en cherchant la tuile dans la bibliothèque. Or la
 * tuile y est ajoutée par builder-composants.js dans SON « load », qui
 * passe après : rien n'était trouvé, rien n'était câblé, et seul le
 * glisser-déposer ouvrait l'invite. Un premier correctif (26/11) n'avait
 * traité que le libellé cherché, et son test ne lisait que du texte.
 *
 * Ce test EXÉCUTE donc ai-page-builder.js dans Node, dans l'ordre réel :
 * l'éditeur est créé alors que la tuile n'existe PAS encore, puis on clique.
 */
final class EditeurTuileClicTest extends TestCase
{
    private const HARNAIS = <<<'JS'
        const fs = require('fs');
        const vm = require('vm');
        const [fichier, brut] = process.argv.slice(-2);
        const scenario = JSON.parse(brut);

        const ecoutes = [];
        const vu = { selections: 0, fins: 0, offres: [], retours: 0 };
        // La toile de l'éditeur, avec ou sans invite déjà ouverte.
        const champ = { scrollIntoView: function () {}, focus: function () { vu.retours++; } };
        const toile = { contentDocument: { querySelector: function (selecteur) { return scenario.inviteOuverte && '.sendly-ia-invite input' === selecteur ? champ : null; } } };
        const window = {
          MauticGrapesJsPlugins: [],
          SendlyAiConfig: scenario.offre ? { teaser: true } : { enabled: true },
          SendlyAiUpsell: { ouvrir: function (contexte) { vu.offres.push(contexte); } },
        };
        // Aucune tuile dans la page à la création de l'éditeur.
        const document = {
          addEventListener: function (type, fn, capture) { ecoutes.push({ type: type, fn: fn, capture: capture === true }); },
          querySelector: function (selecteur) { return '.builder-panel .gjs-frame' === selecteur ? toile : null; },
          querySelectorAll: function () { return []; },
        };
        const mQuery = function () { return { length: 0 }; };
        vm.runInNewContext(fs.readFileSync(fichier, 'utf8'), { window: window, document: document, mQuery: mQuery, console: console, setTimeout: setTimeout });

        const greffon = window.MauticGrapesJsPlugins.filter(function (g) { return 'sendly-ai-page' === g.name; })[0];
        const charges = [];
        const editor = {
          on: function (evenement, fn) { if ('load' === evenement) { charges.push(fn); } },
          Commands: { add: function () {} },
          getSelected: function () { vu.selections++; return null; },
          // L'invite s'ouvrirait en fin de page : on s'arrête là.
          getWrapper: function () { vu.fins++; throw new Error('fin de page'); },
        };
        greffon.plugin(editor);

        function bloc(libelle, dansLaBibliotheque) {
          const el = {
            textContent: libelle,
            closest: function (selecteur) { return '.builder-panel' === selecteur && dansLaBibliotheque ? {} : null; },
          };
          // Le clic tombe sur l'icône ou le libellé, DANS la tuile.
          return { closest: function (selecteur) { return '.gjs-block' === selecteur ? el : null; } };
        }
        function cliquer(cible) {
          ecoutes.filter(function (e) { return 'click' === e.type; }).forEach(function (e) {
            try { e.fn({ target: cible }); } catch (erreur) { if ('fin de page' !== erreur.message) { throw erreur; } }
          });
        }

        const releve = { ecoutesClic: ecoutes.filter(function (e) { return 'click' === e.type; }).length, capture: ecoutes.some(function (e) { return 'click' === e.type && e.capture; }), chargesNonLancees: charges.length };
        cliquer(bloc('Texte', true));
        cliquer(bloc('Sendly Copilot', false));
        cliquer({});
        releve.apresAutresClics = { selections: vu.selections, fins: vu.fins, offres: vu.offres.length };
        cliquer(bloc(scenario.libelle, true));
        releve.apresTuile = { selections: vu.selections, fins: vu.fins, offres: vu.offres, retours: vu.retours };
        process.stdout.write(JSON.stringify(releve));
        JS;

    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    private function executer(array $scenario): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ('' === $node) {
            if ('true' === getenv('GITHUB_ACTIONS')) {
                self::fail('Node est introuvable dans le job PHPUnit : ce test doit tourner en CI.');
            }
            self::markTestSkipped('Node absent sur ce poste.');
        }

        $fichier = __DIR__.'/../../Assets/js/ai-page-builder.js';
        self::assertFileExists($fichier);

        $proc = proc_open(
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

        $resultat = json_decode($sortie, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($resultat);

        return $resultat;
    }

    /**
     * Les libellés que builder-composants.js donne réellement à la tuile.
     *
     * @return list<string>
     */
    private static function libellesDeLaTuile(): array
    {
        $tuile = (string) file_get_contents(__DIR__.'/../../../EwebSaasBundle/Assets/js/builder-composants.js');
        self::assertGreaterThan(0, preg_match_all("/bm\.add\('sendly-ia', \{ label: '([^']+)'/", $tuile, $libelles));

        return array_values(array_unique($libelles[1]));
    }

    public function testLeClicOuvreLInviteMemeSiLaTuileArriveApresLEditeur(): void
    {
        foreach (self::libellesDeLaTuile() as $libelle) {
            $r = $this->executer(['libelle' => $libelle, 'offre' => false]);

            // Une seule écoute, en capture, posée sans attendre le « load ».
            self::assertSame(1, $r['ecoutesClic']);
            self::assertTrue($r['capture']);
            self::assertGreaterThan(0, $r['chargesNonLancees'], 'le scénario ne lance aucun « load » : la tuile n’existe pas encore');
            // Un autre bloc, une tuile hors de la bibliothèque, un clic ailleurs : rien.
            self::assertSame(['selections' => 0, 'fins' => 0, 'offres' => 0], $r['apresAutresClics']);
            // La tuile « '.$libelle.' » : l'invite s'ouvre après la sélection, sinon en fin de page.
            self::assertSame(1, $r['apresTuile']['selections'], 'le clic sur la tuile « '.$libelle.' » doit ouvrir l’invite');
            self::assertSame(1, $r['apresTuile']['fins']);
            self::assertSame([], $r['apresTuile']['offres']);
            self::assertSame(0, $r['apresTuile']['retours']);
        }
    }

    public function testSansLeDroitLeClicOuvreLOffreEtNInserePasDInvite(): void
    {
        $r = $this->executer(['libelle' => 'Sendly Copilot', 'offre' => true]);

        self::assertSame(['email'], $r['apresTuile']['offres']);
        self::assertSame(0, $r['apresTuile']['selections']);
        self::assertSame(0, $r['apresTuile']['fins']);
    }

    public function testUneInviteDejaOuverteReprendLaMainAuLieuDEnEmpilerUneAutre(): void
    {
        $r = $this->executer(['libelle' => 'Sendly Copilot', 'offre' => false, 'inviteOuverte' => true]);

        self::assertSame(1, $r['apresTuile']['retours']);
        self::assertSame(0, $r['apresTuile']['selections']);
        self::assertSame(0, $r['apresTuile']['fins']);
    }

    public function testLAncienLibelleResteReconnu(): void
    {
        $r = $this->executer(['libelle' => 'Assistant IA', 'offre' => false]);

        self::assertSame(1, $r['apresTuile']['selections']);
    }
}
