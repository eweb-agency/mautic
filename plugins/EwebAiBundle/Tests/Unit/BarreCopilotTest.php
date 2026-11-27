<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * La barre Copilot ancrée en bas du canevas de l'éditeur (27/11/2026).
 *
 * Retour du propriétaire : la tuile « Sendly Copilot » est trop enfouie
 * parmi les composants. La barre devient l'entrée principale : elle se pose
 * au chargement de l'éditeur, dit où la section arrivera, génère sur place
 * avec le contexte de la page, et mène à l'offre sans le droit Copilot.
 *
 * Le test EXÉCUTE ai-page-builder.js dans Node, sur un petit DOM factice
 * (la barre est construite élément par élément pour cela), puis tape,
 * clique et répond à la place du serveur.
 */
final class BarreCopilotTest extends TestCase
{
    private const HARNAIS = <<<'JS'
        const fs = require('fs');
        const vm = require('vm');
        const [fichier, brut] = process.argv.slice(-2);
        const sc = JSON.parse(brut);

        // ── Un DOM factice, juste ce qu'il faut ─────────────────────────────
        function Doc(nom) { this.nom = nom; this.activeElement = null; this.head = new El(this, 'head'); this.body = new El(this, 'body'); this.documentElement = { lang: 'fr' }; }
        Doc.prototype.createElement = function (tag) { return new El(this, tag); };
        Doc.prototype.getElementById = function (id) { return trouver(this.head, function (e) { return e.attrs.id === id; }) || trouver(this.body, function (e) { return e.attrs.id === id; }); };
        function El(doc, tag) {
          this.ownerDocument = doc; this.tagName = tag; this.attrs = {}; this.children = []; this.ecoutes = {}; this.style = {};
          this.textContent = ''; this.innerHTML = ''; this.value = ''; this.disabled = false; this.nodeType = 1; this.parentNode = null;
          var soi = this;
          this.classList = {
            add: function (c) { soi.classes().indexOf(c) < 0 && soi.setAttribute('class', (soi.classes().concat([c])).join(' ')); },
            remove: function (c) { soi.setAttribute('class', soi.classes().filter(function (x) { return x !== c; }).join(' ')); },
            toggle: function (c, oui) { (undefined === oui ? soi.classes().indexOf(c) < 0 : oui) ? this.add(c) : this.remove(c); },
            contains: function (c) { return soi.classes().indexOf(c) >= 0; },
          };
        }
        El.prototype.classes = function () { return String(this.attrs['class'] || '').split(/\s+/).filter(Boolean); };
        El.prototype.setAttribute = function (k, v) { this.attrs[k] = String(v); };
        El.prototype.getAttribute = function (k) { return undefined === this.attrs[k] ? null : this.attrs[k]; };
        El.prototype.appendChild = function (e) { e.parentNode = this; this.children.push(e); return e; };
        El.prototype.addEventListener = function (t, f) { (this.ecoutes[t] = this.ecoutes[t] || []).push(f); };
        El.prototype.declencher = function (t, ev) { ev = ev || {}; ev.target = ev.target || this; ev.preventDefault = function () {}; ev.stopPropagation = function () {}; (this.ecoutes[t] || []).forEach(function (f) { f(ev); }); };
        El.prototype.focus = function () { this.ownerDocument.activeElement = this; this.declencher('focus'); };
        El.prototype.blur = function () { if (this.ownerDocument.activeElement === this) { this.ownerDocument.activeElement = null; } this.declencher('blur'); };
        El.prototype.scrollIntoView = function () {};
        El.prototype.remove = function () {};
        El.prototype.closest = function () { return null; };
        Object.defineProperty(El.prototype, 'lastChild', { get: function () { return this.children[this.children.length - 1] || new El(this.ownerDocument, 'span'); } });
        Object.defineProperty(El.prototype, 'firstElementChild', { get: function () { return this.children[0] || null; } });
        El.prototype.querySelector = function (sel) {
          var m = sel.match(/^\.([\w-]+)$/);
          var t = m && trouver(this, function (e) { return e.classes().indexOf(m[1]) >= 0; });
          // Ce que l'innerHTML aurait créé : un élément muet, pour ne pas casser.
          return t || new El(this.ownerDocument, 'span');
        };
        El.prototype.querySelectorAll = function () { return []; };
        function trouver(racine, test) {
          for (var i = 0; i < racine.children.length; i++) { var e = racine.children[i]; if (test(e)) { return e; } var r = trouver(e, test); if (r) { return r; } }
          return null;
        }

        const document = new Doc('page');
        document.addEventListener = function () {};
        const toile = new Doc('toile');
        toile.querySelector = function (s) {
          if ('[data-sx-accent]' === s && sc.jetons) { return { getAttribute: function (k) { return { 'data-sx-accent': '#B4532A', 'data-sx-display': 'Georgia, serif' }[k] || null; } }; }
          return null;
        };
        toile.body.querySelectorAll = function () { return []; };
        const fenetreToile = { getComputedStyle: function (el) { return { backgroundColor: el.getAttribute('data-fond') || 'rgba(0, 0, 0, 0)' }; } };
        const cadre = { contentDocument: toile, contentWindow: fenetreToile };
        document.querySelector = function (s) { return '.builder-panel .gjs-frame' === s ? cadre : null; };

        // ── L'éditeur factice : un wrapper et des composants ────────────────
        function Comp(html, parent) {
          var soi = this;
          this.html = html; this._parent = parent; this._enfants = [];
          var el = new El(toile, 'div');
          var m = String(html).match(/data-sendly-(invite|barre)="1"/);
          if (m) { el.setAttribute('data-sendly-' + m[1], '1'); }
          if (/data-fond/.test(html)) { el.setAttribute('data-fond', 'rgb(247, 243, 236)'); }
          this.view = { el: el };
        }
        Comp.prototype.parent = function () { return this._parent; };
        Comp.prototype.index = function () { return this._parent ? this._parent._enfants.indexOf(this) : 0; };
        Comp.prototype.set = function () {};
        Comp.prototype.get = function () { return ''; };
        Comp.prototype.remove = function () { var p = this._parent; if (p) { p._enfants.splice(p._enfants.indexOf(this), 1); } };
        Comp.prototype.components = function () { var l = this._enfants; return { length: l.length, at: function (i) { return l[i]; }, map: function (f) { return l.map(f); } }; };
        Comp.prototype.append = function (html, opts) { var c = new Comp(html, this); var at = opts && undefined !== opts.at ? opts.at : this._enfants.length; this._enfants.splice(at, 0, c); return [c]; };
        const wrapper = new Comp('wrapper', null);
        const s0 = wrapper.append('<section>Accueil</section>')[0];
        const s1 = wrapper.append('<section data-fond>Avantages</section>')[0];
        const titreDansS1 = s1.append('<h2>Titre</h2>')[0];

        const conteneur = new El(document, 'div');
        const ecoutes = {};
        const editor = {
          on: function (evs, f) { evs.split(' ').forEach(function (e) { (ecoutes[e] = ecoutes[e] || []).push(f); }); },
          Commands: { add: function () {} },
          getSelected: function () { return sc.selection ? titreDansS1 : null; },
          getWrapper: function () { return wrapper; },
          getHtml: function () { return ''; },
          Canvas: { getElement: function () { return conteneur; } },
          DomComponents: { clear: function () {} },
        };

        // ── Le serveur factice ──────────────────────────────────────────────
        const appels = [];
        const mQuery = function (x) {
          if ('function' === typeof x) { return; }
          if ('form[name="page"]' === x) { return { length: 1 }; }
          if ('#page_title' === x) { return { val: function () { return 'Atelier Terre'; }, length: 1 }; }
          return { length: 0, val: function () { return ''; } };
        };
        mQuery.ajax = function (o) {
          var appel = { corps: JSON.parse(o.data) };
          appels.push(appel);
          var d = { done: function (f) { appel.ok = f; return d; }, fail: function (f) { appel.ko = f; return d; } };
          return d;
        };
        const offres = [];
        const window = {
          MauticGrapesJsPlugins: [],
          SendlyAiConfig: sc.offre ? { teaser: true } : { enabled: true, endpoint: '/s/ai/generate' },
          SendlyAiUpsell: { ouvrir: function (c) { offres.push(c); } },
        };
        const sessionStorage = { getItem: function () { return null; }, removeItem: function () {} };
        vm.runInNewContext(fs.readFileSync(fichier, 'utf8'), { window: window, document: document, mQuery: mQuery, console: console, setTimeout: setTimeout, sessionStorage: sessionStorage, Promise: Promise, JSON: JSON });

        window.MauticGrapesJsPlugins.filter(function (g) { return 'sendly-ai-page' === g.name; })[0].plugin(editor);
        (ecoutes.load || []).forEach(function (f) { f(); });

        const dock = conteneur.children[0];
        const r = { dock: !!dock && dock.classList.contains('sendly-dock'), style: !!document.getElementById('sendly-dock-css') };
        const champ = dock.querySelector('.sendly-dock-champ');
        r.indication = champ.getAttribute('placeholder');
        const sections = function () { return wrapper._enfants.map(function (c) { return c.view.el.getAttribute('data-sendly-invite') ? 'invite' : (c.view.el.getAttribute('data-sendly-barre') ? 'barre' : c.html); }); };

        (async function () {
          champ.focus();
          r.ouvert = dock.classList.contains('ouvert');
          r.lieu = dock.querySelector('.sendly-dock-lieu-txt').textContent;
          if (sc.idee) {
            var idees = []; (function tous(e) { e.children.forEach(function (x) { if (x.classes().indexOf('sendly-dock-idee') >= 0) { idees.push(x); } tous(x); }); })(dock);
            r.idees = idees.length;
            idees[0].declencher('click');
          } else {
            champ.value = 'Une section de témoignages';
            champ.declencher('keydown', { key: 'Enter' });
          }
          r.appels = appels.length;
          r.offres = offres;
          if (appels.length) {
            r.corps = appels[0].corps;
            r.contexte = appels[0].corps.context ? JSON.parse(appels[0].corps.context) : null;
            r.pendant = { sections: sections(), enCours: dock.classList.contains('en-cours'), desactive: champ.disabled };
            if (sc.echec) { appels[0].ko({ status: 500 }); } else { appels[0].ok({ text: '<section class="sab12-section">Témoignages</section>' }); }
            await new Promise(function (ok) { setTimeout(ok, 20); });
            r.apres = { sections: sections(), enCours: dock.classList.contains('en-cours'), valeur: champ.value, erreur: dock.querySelector('.sendly-dock-erreur').textContent };
          }
          process.stdout.write(JSON.stringify(r));
        })().catch(function (e) { console.error(e); process.exit(1); });
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

        $proc = proc_open(
            [$node, '-e', self::HARNAIS, '--', __DIR__.'/../../Assets/js/ai-page-builder.js', json_encode($scenario, JSON_THROW_ON_ERROR)],
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

    public function testLaBarreSePoseAuChargementEtDitOuLaSectionArrivera(): void
    {
        $r = $this->executer(['selection' => false]);

        self::assertTrue($r['dock'], 'la barre est posée dans le canevas');
        self::assertTrue($r['style']);
        self::assertSame('Décrivez une section à ajouter…', $r['indication']);
        self::assertTrue($r['ouvert'], 'au focus, la barre déplie ses idées');
        self::assertSame('À la fin de la page', $r['lieu']);
    }

    public function testLaSectionArriveApresLaSectionSelectionneeAvecLeContexteDeLaPage(): void
    {
        $r = $this->executer(['selection' => true, 'jetons' => true]);

        self::assertSame('Après la section sélectionnée', $r['lieu']);
        self::assertSame(1, $r['appels']);
        self::assertSame('generate', $r['corps']['mode']);
        self::assertSame('page', $r['corps']['surface']);
        self::assertSame('Une section de témoignages', $r['corps']['instruction']);
        self::assertSame('French', $r['corps']['uiLang']);
        // Le contexte : les jetons de la page, le fond de la section du dessus, le nom de la page.
        self::assertSame('#B4532A', $r['contexte']['tokens']['accent']);
        self::assertSame('#F7F3EC', $r['contexte']['previous']);
        self::assertSame('Atelier Terre', $r['contexte']['title']);
        // Pendant la génération : la place est réservée APRÈS la section qui
        // contient la sélection (pas dans son titre), la barre attend.
        self::assertSame(['<section>Accueil</section>', '<section data-fond>Avantages</section>', 'invite'], $r['pendant']['sections']);
        self::assertTrue($r['pendant']['enCours']);
        self::assertTrue($r['pendant']['desactive']);
        // Après : la section est là, coiffée de sa barre Garder / Annuler, la barre Copilot est libre.
        self::assertSame(['<section>Accueil</section>', '<section data-fond>Avantages</section>', 'barre', '<section class="sab12-section">Témoignages</section>'], $r['apres']['sections']);
        self::assertFalse($r['apres']['enCours']);
        self::assertSame('', $r['apres']['valeur']);
        self::assertSame('', $r['apres']['erreur']);
    }

    public function testUneIdeeLanceLaGeneration(): void
    {
        $r = $this->executer(['selection' => false, 'idee' => true]);

        self::assertSame(6, $r['idees']);
        self::assertSame(1, $r['appels']);
        self::assertStringContainsString('titre fort', $r['corps']['instruction']);
    }

    public function testUnEchecLeDitEtGardeLaConsigne(): void
    {
        $r = $this->executer(['selection' => false, 'echec' => true]);

        self::assertFalse($r['apres']['enCours']);
        self::assertSame('Une section de témoignages', $r['apres']['valeur']);
        self::assertSame('La génération a échoué. Réessayez dans un instant.', $r['apres']['erreur']);
    }

    public function testSansLeDroitLaBarreMeneALOffre(): void
    {
        $r = $this->executer(['selection' => false, 'offre' => true]);

        self::assertSame(0, $r['appels'], 'aucune génération sans le droit');
        self::assertContains('page', $r['offres']);
        self::assertFalse($r['ouvert']);
    }
}
