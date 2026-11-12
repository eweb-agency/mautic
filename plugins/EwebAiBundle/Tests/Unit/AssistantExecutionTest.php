<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Sendly Copilot ne dit jamais « fait » quand rien n'est fait (lot 1,
 * 12/11/2026).
 *
 * Ces tests EXÉCUTENT ai-assistant.js tel qu'il est livré, dans Node (présent
 * dans le job PHPUnit de la CI), avec un faux mQuery : on passe par la vraie
 * porte, le contexte d'aide, sa requête, puis la réponse du serveur, les
 * créations qui échouent, et le tour suivant.
 *
 * Ce qu'ils verrouillent, constaté dans le code ou en recette :
 *  - « ✓ création en cours » affiché AVANT le résultat, et des échecs muets ;
 *  - des actions écartées par le serveur sans un mot ;
 *  - une seule création par message, les suivantes oubliées en silence ;
 *  - une réponse lente exécutée sur un autre écran que celui de la question ;
 *  - un historique où le modèle relisait « j'ai créé » sans trace du résultat ;
 *  - un message d'échec unique pour l'offre, le service, la demande, le réseau ;
 *  - les éditeurs de texte enrichi invisibles du relevé d'écran.
 */
final class AssistantExecutionTest extends TestCase
{
    private const HARNAIS = <<<'JS'
'use strict';
const fs = require('fs');
const vm = require('vm');
const fichier = process.argv[1];
const sc = JSON.parse(process.argv[2]);
const rien = function () {};

// ── Faux DOM : champs d'un formulaire, dont des textareas sous éditeur riche
const editeurs = new Map();
const champs = (sc.champs || []).map(function (c) {
  const attrs = { name: c.name, id: c.id || '', type: c.type || '' };
  const el = {
    tagName: c.tag || 'INPUT', name: c.name, id: c.id || '', masque: !!c.masque,
    type: c.tag === 'TEXTAREA' ? 'textarea' : (c.type || 'text'),
    value: c.value || '', checked: false,
    getAttribute: function (k) { return attrs[k] ? attrs[k] : null; },
    options: []
  };
  if (c.riche) {
    const ed = { donnees: c.riche, getData: function () { return this.donnees; }, setData: function (d) { this.donnees = d; }, updateSourceElement: function () { el.value = this.donnees; } };
    editeurs.set(el, ed);
  }
  return el;
});
const formulaire = { tagName: 'FORM', elements: champs, getAttribute: function () { return null; } };
const doc = {
  documentElement: { lang: 'fr' },
  addEventListener: rien,
  getElementById: function () { return null; },
  querySelector: function () { return null; },
  createElement: function () { return { innerHTML: '', get textContent() { return this.innerHTML.replace(/<[^>]*>/g, ''); } }; }
};

function enveloppe(liste) {
  const w = {
    length: liste.length,
    each: function (fn) { for (let i = 0; i < liste.length; i++) { if (fn.call(liste[i], i, liste[i]) === false) { break; } } return w; },
    find: function (sel) {
      let tous = [];
      liste.forEach(function (x) { tous = tous.concat(x === doc ? formulaire.elements : (x.elements || [])); });
      const nom = /^\[name="(.*)"\]$/.exec(sel);
      return enveloppe(nom ? tous.filter(function (e) { return e.name === nom[1]; }) : tous);
    },
    filter: function (f) {
      if (typeof f === 'function') { return enveloppe(liste.filter(function (e, i) { return f.call(e, i, e); })); }
      return enveloppe(liste.filter(function (e) { return e.type !== 'hidden'; }));
    },
    closest: function () { return enveloppe([]); },
    next: function () { return enveloppe([]); },
    is: function (sel) {
      const e = liste[0];
      if (!e) { return false; }
      if (sel === ':visible') { return !e.masque; }
      if (sel === 'select') { return e.tagName === 'SELECT'; }
      if (sel === 'textarea') { return e.tagName === 'TEXTAREA'; }
      return false;
    },
    val: function (v) {
      if (arguments.length === 0) { return liste[0] ? liste[0].value : undefined; }
      liste.forEach(function (e) { e.value = v; });
      return w;
    },
    trigger: function () { return w; },
    first: function () { return enveloppe(liste.slice(0, 1)); },
    get: function (i) { return liste[i]; },
    on: function () { return w; },
    ajaxComplete: rien
  };
  return w;
}

const requetes = [];
function mQuery(x) {
  if (x === 'form') { return enveloppe([formulaire]); }
  if (x && typeof x === 'object') { return enveloppe([x]); }
  return enveloppe([]);
}
mQuery.ajax = function (o) { requetes.push(o); return { done: rien }; };

const location = { pathname: sc.chemin || '/s/forms/new', search: '', assign: function (u) { navigations.push(u); } };
const navigations = [];
const minuteries = [];
const bac = {
  window: { SendlyAiConfig: { enabled: true, assistEndpoint: '/s/ai/assist' }, location: location },
  document: doc,
  Mautic: { translate: function (k) { return k; } },
  mQuery: mQuery,
  setTimeout: function (fn) { minuteries.push(fn); },
  sessionStorage: { setItem: rien },
  ckEditors: editeurs
};
vm.createContext(bac);
vm.runInContext(fs.readFileSync(fichier, 'utf8'), bac, { filename: fichier });

const sortie = {};
const SA = bac.window.SendlyAssistant;

if (sc.erreurs) {
  sortie.erreurs = sc.erreurs.map(function (x) { return SA.messageErreur(x); });
  process.stdout.write(JSON.stringify(sortie));
  process.exit(0);
}

// Une conversation : on joue la vraie api de la coquille (ia/note/finish/fail).
const tours = [];
function api() {
  return {
    history: function () { return []; },
    ia: function (html, extra) { const t = Object.assign({ role: 'ia', html: html }, extra || {}); tours.push(t); return t; },
    note: function (tour, texte) { tour.html += '<div class="sendly-assist-mut">' + texte + '</div>'; },
    finish: rien,
    fail: function (m) { tours.push({ role: 'ia', html: m, echec: true }); }
  };
}
const aide = bac.window.SendlyAssistantContexts.filter(function (c) { return c.id === 'help'; })[0];
aide.onSend('premiere question', api());
const envoi = requetes.filter(function (r) { return r.url === '/s/ai/assist'; })[0];
sortie.contexte = envoi.data.context;
if (sc.changerEcran) { location.pathname = '/s/segments'; }
envoi.success(sc.reponse);
sortie.tour = tours[0] || null;
sortie.creations = requetes.filter(function (r) { return r.url !== '/s/ai/assist'; }).map(function (r) { return r.url; });
sortie.champs = {};
champs.forEach(function (e) { sortie.champs[e.name] = editeurs.get(e) ? editeurs.get(e).getData() : e.value; });

if (sc.echecCreation) {
  const creation = requetes.filter(function (r) { return r.url !== '/s/ai/assist'; })[0];
  creation.error(sc.echecCreation);
  sortie.tourApresEchec = tours[0];
}

if (sc.annuler) {
  aide.onUndo(sc.reponse.turnId, { markUndone: rien });
  sortie.annule = {};
  champs.forEach(function (e) { sortie.annule[e.name] = editeurs.get(e) ? editeurs.get(e).getData() : e.value; });
}

if (sc.tourSuivant) {
  // Le tour suivant : la question, la réponse (avec son résultat réel), une
  // question échouée (tour sans texte ni résultat), puis la nouvelle.
  sortie.historique = SA.historiqueAide([
    { role: 'user', text: 'premiere question' }
  ].concat(tours).concat([
    { role: 'ia', html: 'Le service est momentanément indisponible.', echec: true },
    { role: 'user', text: 'et maintenant ?' }
  ]), 'et maintenant ?');
}
process.stdout.write(JSON.stringify(sortie));
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

        $fichier = __DIR__.'/../../Assets/js/ai-assistant.js';
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

    /** @return array<string, mixed> */
    private function formulaire(): array
    {
        return [
            'chemin' => '/s/forms/new',
            'champs' => [
                ['name' => 'mauticform[name]', 'id' => 'mauticform_name', 'type' => 'text', 'value' => ''],
                // La description : textarea MASQUÉ sous un éditeur riche.
                ['name' => 'mauticform[description]', 'id' => 'mauticform_description', 'tag' => 'TEXTAREA', 'masque' => true, 'riche' => '<p>Ancien texte</p>'],
            ],
        ];
    }

    private static function texte(mixed $tour): string
    {
        return html_entity_decode(strip_tags(str_replace('</div>', "\n", (string) ($tour['html'] ?? ''))), ENT_QUOTES);
    }

    // ── Créations : jamais de ✓ d'avance, jamais d'échec muet ─────────────

    public function testUneCreationNAnnoncePasDeSuccesAvantSonResultat(): void
    {
        $r = $this->executer($this->formulaire() + ['reponse' => [
            'answer'  => 'Je crée le formulaire.', 'turnId' => 't1', 'dropped' => [],
            'actions' => [['type' => 'create_form', 'name' => 'Devis', 'fields' => [['label' => 'E-mail', 'type' => 'email']]]],
        ]]);

        $texte = self::texte($r['tour']);
        self::assertStringContainsString('Création en cours (en brouillon)', $texte);
        self::assertStringNotContainsString('✓', $texte, 'aucun succès annoncé avant la réponse du serveur');
        self::assertSame(['/s/ai/form/create'], $r['creations']);
        self::assertStringContainsString('creation requested: create_form', (string) $r['tour']['result']);
    }

    public function testUnEchecDeCreationEstDitAvecSaRaison(): void
    {
        $r = $this->executer($this->formulaire() + [
            'reponse' => [
                'answer'  => 'Je crée le rapport.', 'turnId' => 't1', 'dropped' => [],
                'actions' => [['type' => 'create_report', 'name' => 'Ouvertures', 'source' => 'email.stats']],
            ],
            // `forbidden` était muet avant le 12/11 (5 codes sur 10 avaient un motif).
            'echecCreation' => ['status' => 403, 'responseJSON' => ['error' => 'forbidden']],
        ]);

        $texte = self::texte($r['tourApresEchec']);
        self::assertStringContainsString('✗ La création n’a pas abouti : votre compte n’a pas le droit de créer cet élément.', $texte);
        self::assertStringContainsString('creation failed: forbidden', (string) $r['tourApresEchec']['result'], 'le tour suivant le saura');
    }

    public function testUnCodeDEchecInconnuADeToutesFaconsUnMotif(): void
    {
        $r = $this->executer($this->formulaire() + [
            'reponse' => [
                'answer'  => 'Je crée le rapport.', 'turnId' => 't1', 'dropped' => [],
                'actions' => [['type' => 'create_report', 'name' => 'Ouvertures', 'source' => 'email.stats']],
            ],
            'echecCreation' => ['status' => 500, 'responseJSON' => null],
        ]);

        self::assertStringContainsString('✗ La création n’a pas abouti : le serveur a refusé la demande.', self::texte($r['tourApresEchec']));
    }

    public function testUneSecondeCreationDuMemeMessageEstDiteNonLancee(): void
    {
        $r = $this->executer($this->formulaire() + ['reponse' => [
            'answer'  => 'Je crée le formulaire et le rapport.', 'turnId' => 't1', 'dropped' => [],
            'actions' => [
                ['type' => 'create_form', 'name' => 'Devis', 'fields' => [['label' => 'E-mail', 'type' => 'email']]],
                ['type' => 'create_report', 'name' => 'Ouvertures', 'source' => 'email.stats'],
            ],
        ]]);

        self::assertSame(['/s/ai/form/create'], $r['creations'], 'une seule création part');
        self::assertStringContainsString('✗ Non lancé dans ce message : créer le rapport. Redemandez-le ensuite.', self::texte($r['tour']));
    }

    // ── Actions écartées, champs introuvables, écran changé ──────────────

    public function testLesActionsEcarteesParLeServeurSontDites(): void
    {
        $r = $this->executer($this->formulaire() + ['reponse' => [
            'answer'  => 'Je crée le formulaire.', 'turnId' => 't1',
            'actions' => [], 'dropped' => ['create_form'],
        ]]);

        self::assertStringContainsString('✗ Non exécuté : créer le formulaire. Précisez votre demande', self::texte($r['tour']));
        self::assertSame([], $r['creations']);
    }

    public function testUnChampIntrouvableNEstPasCompteCommeRempli(): void
    {
        $r = $this->executer($this->formulaire() + ['reponse' => [
            'answer'  => 'J’ai rempli le slogan.', 'turnId' => 't1', 'dropped' => [],
            'actions' => [['type' => 'fill_field', 'field' => 'mauticform[slogan]', 'value' => 'x']],
        ]]);

        $texte = self::texte($r['tour']);
        self::assertStringContainsString('✗ Aucun champ rempli : le champ demandé est introuvable sur cet écran.', $texte);
        self::assertStringNotContainsString('✓', $texte);
    }

    public function testUneReponseArriveeSurUnAutreEcranNExecuteRien(): void
    {
        $r = $this->executer($this->formulaire() + [
            'changerEcran' => true,
            'reponse'      => [
                'answer'  => 'J’ai rempli le nom.', 'turnId' => 't1', 'dropped' => [],
                'actions' => [['type' => 'fill_field', 'field' => 'mauticform[name]', 'value' => 'Devis']],
            ],
        ]);

        self::assertSame('', $r['champs']['mauticform[name]'], 'rien n’est écrit sur le nouvel écran');
        self::assertStringContainsString('Rien n’a été exécuté : vous avez changé d’écran', self::texte($r['tour']));
        self::assertStringContainsString('nothing executed', (string) $r['tour']['result']);
    }

    // ── Éditeurs de texte enrichi (recette du 12/11) ──────────────────────

    public function testLaDescriptionSousEditeurRicheEstVueRempliePuisAnnulee(): void
    {
        $r = $this->executer($this->formulaire() + [
            'reponse' => [
                'answer'  => 'J’ai rempli la description.', 'turnId' => 't1', 'dropped' => [],
                'actions' => [['type' => 'fill_field', 'field' => 'mauticform[description]', 'value' => "Demande de devis\n<script>x</script>"]],
            ],
            'annuler' => true,
        ]);

        // Vue dans le relevé, avec son TEXTE (pas son HTML).
        self::assertStringContainsString('- name="mauticform[description]" value="Ancien texte"', (string) $r['contexte']);
        // Remplie par l'éditeur, texte échappé, un paragraphe par ligne.
        self::assertSame('<p>Demande de devis</p><p>&lt;script&gt;x&lt;/script&gt;</p>', $r['champs']['mauticform[description]']);
        self::assertStringContainsString('✓ 1 champ rempli', self::texte($r['tour']));
        // Annulation : l'éditeur retrouve son contenu d'origine.
        self::assertSame('<p>Ancien texte</p>', $r['annule']['mauticform[description]']);
    }

    // ── Historique : le modèle sait ce qui s'est RÉELLEMENT passé ─────────

    public function testLeTourSuivantPorteLeResultatReelDuTourPrecedent(): void
    {
        $r = $this->executer($this->formulaire() + [
            'reponse' => [
                'answer'  => 'Je crée le formulaire.', 'turnId' => 't1',
                'actions' => [], 'dropped' => ['create_form'],
            ],
            'tourSuivant' => true,
        ]);

        self::assertSame([
            ['role' => 'user', 'content' => 'premiere question'],
            ['role' => 'assistant', 'content' => "Je crée le formulaire.\n[What actually happened on screen: rejected as incomplete: create_form]"],
        ], $r['historique'], 'le tour courant part à part, le tour en échec ne part pas');
    }

    // ── Messages d'échec selon la cause ───────────────────────────────────

    public function testLeMessageDEchecDitLaCause(): void
    {
        $r = $this->executer(['erreurs' => [
            ['status' => 403, 'responseJSON' => ['error' => 'entitlement']],
            ['status' => 503, 'responseJSON' => ['error' => 'disabled']],
            ['status' => 400, 'responseJSON' => ['error' => 'bad_request']],
            ['status' => 0],
            ['status' => 502, 'responseJSON' => ['error' => 'ai_failed']],
        ]]);

        self::assertSame([
            'Sendly Copilot fait partie de l’offre Pro de votre espace.',
            'Sendly Copilot n’est pas activé sur votre espace.',
            'Je n’ai pas compris la demande. Reformulez-la en une phrase.',
            'La connexion a été interrompue. Vérifiez votre réseau, puis réessayez.',
            // Mautic.translate du banc renvoie la clé : le repli s'applique.
            'Le service est momentanément indisponible. Réessayez dans un instant.',
        ], $r['erreurs']);
    }
}
