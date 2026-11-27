<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * La pastille « 9 123 contacts correspondants » du formulaire de segment,
 * refaite le 27/11/2026 selon la DA de Sendly (pilule blanche, rond bleu
 * clair, nombre en bleu nuit, plus de liseré à gauche).
 *
 * Le test EXÉCUTE segment-live-count.js dans Node et lit ce que la pastille
 * affiche à chaque étape : le nombre, le recalcul (le dernier nombre reste
 * en place, estompé), les critères à compléter, la panne.
 */
final class SegmentPastilleTest extends TestCase
{
    private const HARNAIS = <<<'JS'
        const fs = require('fs');
        const vm = require('vm');
        const [fichier, scenario] = process.argv.slice(-2);

        const elements = {};
        function El(tag) { this.tagName = tag; this.attrs = {}; this.innerHTML = ''; this.className = ''; this.textContent = ''; }
        El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); if ('id' === k) { elements[v] = this; } };
        El.prototype.getAttribute = function (k) { return undefined === this.attrs[k] ? null : this.attrs[k]; };
        Object.defineProperty(El.prototype, 'id', { set: function (v) { this.setAttribute('id', v); }, get: function () { return this.attrs.id; } });
        const document = {
          documentElement: { lang: 'fr' },
          head: { appendChild: function (e) { elements[e.id] = e; } },
          createElement: function (t) { return new El(t); },
          getElementById: function (id) { return elements[id] || null; },
        };
        const appels = [];
        const gestes = {};
        const delegue = { off: function () { return delegue; }, on: function (ev, sel, fn) { gestes[ev] = fn; return delegue; } };
        let criteres = 'leadlist[filters][0][field]=country';
        const mQuery = function (x) {
          if ('function' === typeof x) { x(); return; }
          if ('#available_segment_filters' === x) { return { length: 1, closest: function () { return { before: function (e) { elements[e.id] = e; } }; } }; }
          if ('#leadlist_filters' === x) { return { length: 1, off: delegue.off, on: delegue.on, find: function () { return { serialize: function () { return criteres; } }; } }; }
          return { length: 0 };
        };
        mQuery.ajax = function (o) { appels.push(o); };
        const window = { SendlySegmentCountConfig: { endpoint: '/s/segments/count' } };
        const Mautic = { translate: function (k) { return k; } };
        vm.runInNewContext(fs.readFileSync(fichier, 'utf8'), { window: window, document: document, mQuery: mQuery, Mautic: Mautic, console: console, setTimeout: setTimeout, clearTimeout: clearTimeout, Number: Number, String: String });

        const pastille = function () { var e = elements['sendly-seg-count']; return { html: e.innerHTML, classe: e.className, occupe: e.getAttribute('aria-busy') }; };
        const r = { style: !!elements['sendly-seg-count-style'], css: elements['sendly-seg-count-style'] ? elements['sendly-seg-count-style'].textContent : '' };
        (async function () {
          r.enCalcul = pastille();
          appels[0].success({ count: 9123, ignored: 0 });
          r.compte = pastille();
          if ('recalcul' === scenario) {
            // Un critère change : après le délai de saisie, le recalcul part ;
            // le dernier nombre reste affiché pendant ce temps.
            criteres += '&leadlist[filters][1][field]=city';
            gestes['change.sendlySegCount']();
            await new Promise(function (ok) { setTimeout(ok, 800); });
            r.appels = appels.length;
            r.recalcul = pastille();
          }
          if ('partiel' === scenario) {
            appels[0].success({ count: 1, ignored: 2 });
            r.partiel = pastille();
            appels[0].success({ count: null });
            r.indisponible = pastille();
          }
          process.stdout.write(JSON.stringify(r));
        })();
        JS;

    /**
     * @return array<string, mixed>
     */
    private function executer(string $scenario = ''): array
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ('' === $node) {
            if ('true' === getenv('GITHUB_ACTIONS')) {
                self::fail('Node est introuvable dans le job PHPUnit : ce test doit tourner en CI.');
            }
            self::markTestSkipped('Node absent sur ce poste.');
        }
        $proc = proc_open(
            [$node, '-e', self::HARNAIS, '--', __DIR__.'/../../Assets/js/segment-live-count.js', $scenario],
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

    public function testLaPastilleAfficheLeNombreDansLaDa(): void
    {
        $r = $this->executer();

        self::assertTrue($r['style']);
        // La DA : pilule, rond bleu clair, nombre bleu nuit, Helvena ; plus de liseré.
        self::assertStringContainsString('border-radius:999px', $r['css']);
        self::assertStringContainsString('background:#DFEAFF;color:#004FFF', $r['css']);
        self::assertStringContainsString('color:#001248', $r['css']);
        self::assertStringContainsString('Helvena', $r['css']);
        self::assertStringNotContainsString('border-left', $r['css']);

        self::assertSame('calcul', trim($r['enCalcul']['classe']));
        self::assertSame('true', $r['enCalcul']['occupe']);

        self::assertMatchesRegularExpression('/sendly-seg-count-num nouveau">9\x{202F}123</u', $r['compte']['html']);
        self::assertStringContainsString('contacts correspondants', $r['compte']['html']);
        self::assertStringContainsString('<svg', $r['compte']['html']);
        self::assertStringNotContainsString('sendly-seg-count-note', $r['compte']['html']);
        self::assertSame('', trim($r['compte']['classe']));
        self::assertSame('false', $r['compte']['occupe']);
    }

    public function testPendantUnRecalculLeDernierNombreResteEnPlace(): void
    {
        $r = $this->executer('recalcul');

        self::assertSame(2, $r['appels']);
        self::assertSame('calcul', trim($r['recalcul']['classe']));
        self::assertSame('true', $r['recalcul']['occupe']);
        // Le nombre ne saute pas pendant le calcul : il reste, estompé.
        self::assertMatchesRegularExpression('/sendly-seg-count-num">9\x{202F}123</u', $r['recalcul']['html']);
        self::assertStringContainsString('Calcul…', $r['recalcul']['html']);
    }

    public function testLesCriteresACompleterEtLaPanne(): void
    {
        $r = $this->executer('partiel');

        self::assertStringContainsString('>1<', $r['partiel']['html']);
        self::assertStringContainsString('contact correspondant<', $r['partiel']['html']);
        self::assertStringContainsString('<span class="sendly-seg-count-note"><span class="long">Critères à compléter</span><span class="court">À compléter</span></span>', $r['partiel']['html']);
        // Au téléphone, les textes courts : « contact », « À compléter ».
        self::assertStringContainsString('<span class="court">contact</span>', $r['partiel']['html']);
        self::assertStringContainsString('avec-note', $r['partiel']['classe']);
        self::assertStringContainsString('Nombre indisponible', $r['indisponible']['html']);
        self::assertSame('muet', trim($r['indisponible']['classe']));
    }
}
