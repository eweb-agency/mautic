<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use MauticPlugin\EwebAiBundle\Service\ScreenStatePrivacy;
use PHPUnit\Framework\TestCase;

/**
 * Confidentialité des fiches, CÔTÉ NAVIGATEUR (décision proprio 29/10,
 * option A) : sur une fiche contact ou société, le relevé d'écran de
 * l'assistant ne porte plus la VALEUR des champs personnels, et le
 * remplissage (poserChamp) comme l'annulation (instantanés) marchent
 * toujours sur ces champs.
 *
 * Ces tests EXÉCUTENT ai-assistant.js tel qu'il est livré, dans Node (présent
 * dans le job PHPUnit de la CI : `composer install` y lance `npm ci`), avec
 * un faux mQuery minimal qui simule un formulaire. On passe par la vraie
 * porte : le contexte d'aide, son onSend, la requête partie, puis la réponse
 * du serveur et l'annulation.
 */
final class AssistantConfidentialiteTest extends TestCase
{
    /**
     * Le harnais Node. argv : fichier JS, scénario JSON. Sortie : JSON.
     * Le faux mQuery ne couvre que ce que la coquille appelle sur ce chemin.
     */
    private const HARNAIS = <<<'JS'
'use strict';
const fs = require('fs');
const vm = require('vm');
const fichier = process.argv[1];
const sc = JSON.parse(process.argv[2]);
const rien = function () {};

const champs = (sc.champs || []).map(function (c) {
  const attrs = { name: c.name, id: c.id || '', type: c.type || '' };
  return {
    tagName: c.tag || 'INPUT', name: c.name, id: c.id || '',
    type: c.tag === 'SELECT' ? (c.multiple ? 'select-multiple' : 'select-one') : (c.tag === 'TEXTAREA' ? 'textarea' : (c.type || 'text')),
    value: c.value, checked: !!c.checked,
    getAttribute: function (k) { return attrs[k] ? attrs[k] : null; },
    options: (c.options || []).map(function (o) { return { value: o[0], textContent: o[1] }; })
  };
});
const formulaire = { tagName: 'FORM', elements: champs, getAttribute: function (k) { return k === 'name' ? (sc.formulaire || null) : null; } };
const libelles = {};
(sc.champs || []).forEach(function (c) { if (c.id && c.label) { libelles[c.id] = c.label; } });
const doc = {
  documentElement: { lang: 'fr' },
  addEventListener: rien,
  getElementById: function () { return null; },
  querySelector: function (sel) {
    const m = /^label\[for="(.*)"\]$/.exec(sel);
    return m && libelles[m[1]] ? { textContent: libelles[m[1]] } : null;
  }
};

function enveloppe(liste) {
  const w = {
    length: liste.length,
    each: function (fn) {
      for (let i = 0; i < liste.length; i++) { if (fn.call(liste[i], i, liste[i]) === false) { break; } }
      return w;
    },
    find: function (sel) {
      if (sel === 'option') {
        let o = [];
        liste.forEach(function (x) { o = o.concat(x.options || []); });
        return enveloppe(o);
      }
      let tous = [];
      liste.forEach(function (x) { tous = tous.concat(x === doc ? formulaire.elements : (x.elements || [])); });
      const nom = /^\[name="(.*)"\]$/.exec(sel);
      return enveloppe(nom ? tous.filter(function (e) { return e.name === nom[1]; }) : tous);
    },
    filter: function (f) {
      if (typeof f === 'function') { return enveloppe(liste.filter(function (e, i) { return f.call(e, i, e); })); }
      const types = [], noms = [];
      f.replace(/:not\(\[type=(\w+)\]\)/g, function (_, t) { types.push(t); });
      f.replace(/:not\(\[name\*="([^"]+)"\]\)/g, function (_, n) { noms.push(n); });
      return enveloppe(liste.filter(function (e) {
        return types.indexOf(e.getAttribute('type')) === -1 &&
          noms.every(function (n) { return e.name.indexOf(n) === -1; });
      }));
    },
    closest: function () { return enveloppe([]); },
    next: function () { return enveloppe([]); },
    is: function (sel) {
      const e = liste[0];
      if (!e) { return false; }
      if (sel === ':visible') { return true; }
      if (sel === 'select') { return e.tagName === 'SELECT'; }
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

const bac = {
  window: {
    SendlyAiConfig: { enabled: true, assistEndpoint: '/s/ai/assist' },
    location: { pathname: sc.chemin || '/s/dashboard', assign: rien }
  },
  document: doc,
  Mautic: { translate: function (k) { return k; } },
  mQuery: mQuery,
  setTimeout: rien,
  sessionStorage: { setItem: rien }
};
vm.createContext(bac);
vm.runInContext(fs.readFileSync(fichier, 'utf8'), bac, { filename: fichier });

if (sc.cas) {
  const c = bac.window.SendlyAssistant.confidentialite;
  process.stdout.write(JSON.stringify({ masquee: sc.cas.map(function (x) { return c.masquee(x); }) }));
  process.exit(0);
}

const valeurs = function () {
  const o = {};
  champs.forEach(function (e) { o[e.name] = e.value; });
  return o;
};
const aide = bac.window.SendlyAssistantContexts.filter(function (c) { return c.id === 'help'; })[0];
aide.onSend('question', { history: function () { return []; }, ia: rien, finish: rien, fail: function (m) { throw new Error(m); } });
const envoi = requetes.filter(function (r) { return r.url === '/s/ai/assist'; })[0];
envoi.success({ answer: 'ok', actions: sc.actions || [], turnId: 't1' });
const apres = valeurs();
let annule = null;
aide.onUndo('t1', { markUndone: function () { annule = valeurs(); } });
process.stdout.write(JSON.stringify({ context: envoi.data.context, capacites: envoi.data.actions, apres: apres, annule: annule }));
JS;

    private static function fichier(): string
    {
        $path = __DIR__.'/../../Assets/js/ai-assistant.js';
        self::assertFileExists($path);

        return $path;
    }

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
            [$node, '-e', self::HARNAIS, '--', self::fichier(), json_encode($scenario, JSON_THROW_ON_ERROR)],
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
     * Une fiche contact en édition, avec les noms réels du formulaire LeadType.
     *
     * @return array<string, mixed>
     */
    private function ficheContact(): array
    {
        return [
            'chemin'     => '/s/contacts/edit/42',
            'formulaire' => 'lead',
            'champs'     => [
                ['name' => 'lead[firstname]', 'id' => 'lead_firstname', 'type' => 'text', 'value' => 'Jeanne', 'label' => 'Prénom'],
                ['name' => 'lead[email]', 'id' => 'lead_email', 'type' => 'email', 'value' => 'jeanne.martin@example.fr', 'label' => 'E-mail'],
                ['name' => 'lead[mobile]', 'id' => 'lead_mobile', 'type' => 'tel', 'value' => '06 12 34 56 78', 'label' => 'Mobile'],
                ['name' => 'lead[country]', 'id' => 'lead_country', 'tag' => 'SELECT', 'value' => 'France', 'label' => 'Pays', 'options' => [['', 'Choisir'], ['France', 'France'], ['Belgique', 'Belgique']]],
                ['name' => 'lead[naissance]', 'id' => 'lead_naissance', 'type' => 'text', 'value' => '02/03/1984', 'label' => 'Date de naissance'],
                ['name' => 'lead[accord_rgpd]', 'id' => 'lead_accord_rgpd', 'type' => 'checkbox', 'value' => '1', 'checked' => false, 'label' => 'Accord RGPD'],
                ['name' => 'lead[points]', 'id' => 'lead_points', 'type' => 'number', 'value' => '40', 'label' => 'Points'],
                ['name' => 'lead[stage]', 'id' => 'lead_stage', 'tag' => 'SELECT', 'value' => '3', 'label' => 'Étape', 'options' => [['', 'Choisir'], ['1', 'Prospect'], ['3', 'Client']]],
                ['name' => 'lead[tags][]', 'id' => 'lead_tags', 'tag' => 'SELECT', 'multiple' => true, 'value' => ['vip'], 'label' => 'Tags', 'options' => [['vip', 'vip'], ['salon-2026', 'salon-2026']]],
                // Société liée : select chargé en ajax, il ne porte que
                // l'option choisie. Sa liste serait la valeur.
                ['name' => 'lead[companies][]', 'id' => 'lead_companies', 'tag' => 'SELECT', 'multiple' => true, 'value' => ['12'], 'label' => 'Société', 'options' => [['12', 'Boulangerie Martin']]],
                ['name' => 'lead[_token]', 'type' => 'hidden', 'value' => 'jeton-csrf'],
            ],
        ];
    }

    public function testSurUneFicheContactLaValeurDesCoordonneesNePartPas(): void
    {
        $r       = $this->executer($this->ficheContact());
        $context = (string) $r['context'];

        foreach (['Jeanne', 'jeanne.martin', 'example.fr', '06 12', 'France', '1984', 'Boulangerie', 'jeton-csrf'] as $donnee) {
            self::assertStringNotContainsString($donnee, $context, "« $donnee » ne doit pas partir");
        }
        foreach ([
            '- name="lead[firstname]" label="Prénom" (filled)',
            '- name="lead[email]" label="E-mail" (filled)',
            '- name="lead[mobile]" label="Mobile" (filled)',
            '- name="lead[country]" label="Pays" (filled)',
            '- name="lead[naissance]" label="Date de naissance" (filled)',
            '- name="lead[accord_rgpd]" label="Accord RGPD" (empty)',
            '- name="lead[companies][]" label="Société" (filled)',
        ] as $ligne) {
            self::assertStringContainsString($ligne, $context);
        }
        // Les champs non personnels partent avec leur valeur, comme avant.
        foreach ([
            '- name="lead[points]" label="Points" value="40"',
            '- name="lead[stage]" label="Étape" options=@L1 value="3"',
            '@L1: 1=Prospect | 3=Client',
            '- name="lead[tags][]" label="Tags" options=@L2 value="vip"',
            '@L2: vip | salon-2026',
        ] as $ligne) {
            self::assertStringContainsString($ligne, $context);
        }
        self::assertContains('fill_field', $r['capacites'], 'le remplissage reste proposé au modèle');
        // Le serveur rejoue la même règle : il ne trouve rien de plus à taire.
        self::assertSame($context, ScreenStatePrivacy::mask($context));
    }

    public function testUnChampMasqueSeRemplitEtSAnnuleQuandMeme(): void
    {
        $scenario            = $this->ficheContact();
        $scenario['actions'] = [
            ['type' => 'fill_field', 'field' => 'lead[email]', 'value' => 'nouvelle.adresse@example.fr'],
            ['type' => 'fill_field', 'field' => 'lead[country]', 'value' => 'Belgique'],
        ];

        $r = $this->executer($scenario);

        self::assertSame('nouvelle.adresse@example.fr', $r['apres']['lead[email]']);
        self::assertSame('Belgique', $r['apres']['lead[country]'], 'un select masqué (sans liste envoyée) se remplit quand même');
        self::assertIsArray($r['annule'], 'l annulation doit avoir eu lieu');
        self::assertSame('jeanne.martin@example.fr', $r['annule']['lead[email]'], 'l instantané garde l ancienne valeur, dans le navigateur seulement');
        self::assertSame('France', $r['annule']['lead[country]']);
    }

    public function testUneFicheSocieteEtLaCreationRapideSontCouvertes(): void
    {
        $societe = $this->executer([
            'chemin'     => '/s/companies/edit/7',
            'formulaire' => 'company',
            'champs'     => [
                ['name' => 'company[companyname]', 'id' => 'company_companyname', 'type' => 'text', 'value' => 'Boulangerie Martin', 'label' => 'Nom de la société'],
                ['name' => 'company[companyemail]', 'id' => 'company_companyemail', 'type' => 'email', 'value' => 'contact@boulangerie-martin.fr', 'label' => 'E-mail'],
                ['name' => 'company[companyindustry]', 'id' => 'company_companyindustry', 'tag' => 'SELECT', 'value' => 'Retail', 'label' => 'Secteur', 'options' => [['Agriculture', 'Agriculture'], ['Retail', 'Retail']]],
                ['name' => 'company[score]', 'id' => 'company_score', 'type' => 'number', 'value' => '12', 'label' => 'Score'],
            ],
        ]);
        $context = (string) $societe['context'];
        self::assertStringNotContainsString('Boulangerie', $context);
        self::assertStringNotContainsString('contact@', $context);
        self::assertStringContainsString('- name="company[companyindustry]" label="Secteur" options=@L1 value="Retail"', $context);
        self::assertStringContainsString('- name="company[score]" label="Score" value="12"', $context);

        // Modale « Création rapide » posée sur la LISTE des contacts : le
        // chemin n'est pas une fiche, le formulaire `lead` l'est.
        $rapide = $this->executer([
            'chemin'     => '/s/contacts',
            'formulaire' => 'lead',
            'champs'     => [
                ['name' => 'lead[email]', 'id' => 'lead_email', 'type' => 'email', 'value' => 'paul@example.fr', 'label' => 'E-mail'],
                ['name' => 'lead[lastname]', 'id' => 'lead_lastname', 'type' => 'text', 'value' => 'Durand', 'label' => 'Nom'],
            ],
        ]);
        self::assertStringNotContainsString('paul@', (string) $rapide['context']);
        self::assertStringNotContainsString('Durand', (string) $rapide['context']);
    }

    public function testHorsDesFichesLeReleveNeChangePas(): void
    {
        $email = $this->executer([
            'chemin'     => '/s/emails/edit/3',
            'formulaire' => 'emailform',
            'champs'     => [
                ['name' => 'emailform[subject]', 'id' => 'emailform_subject', 'type' => 'text', 'value' => 'Nos offres de novembre', 'label' => 'Objet'],
                ['name' => 'emailform[fromAddress]', 'id' => 'emailform_fromAddress', 'type' => 'email', 'value' => 'contact@agence.fr', 'label' => 'Adresse de l\'expéditeur'],
            ],
        ]);
        self::assertSame(
            "Screen: /s/emails/edit/3\nForm fields:\n".
            "- name=\"emailform[subject]\" label=\"Objet\" value=\"Nos offres de novembre\"\n".
            "- name=\"emailform[fromAddress]\" label=\"Adresse de l'expéditeur\" value=\"contact@agence.fr\"",
            $email['context']
        );

        $import = $this->executer([
            'chemin'     => '/s/contacts/import/new',
            'formulaire' => 'lead_field_import',
            'champs'     => [
                ['name' => 'lead_field_import[email]', 'id' => 'lead_field_import_email', 'tag' => 'SELECT', 'value' => 'email', 'label' => 'email', 'options' => [['', 'Choisir'], ['email', 'E-mail'], ['companyname', 'Nom de la société']]],
            ],
        ]);
        self::assertSame(
            "Screen: /s/contacts/import/new\nForm fields:\n".
            "- name=\"lead_field_import[email]\" label=\"email\" options=@L1 value=\"email\"\n".
            '@L1: email=E-mail | companyname=Nom de la société',
            $import['context']
        );
    }

    public function testLaRegleDuNavigateurEtCelleDuServeurSontLesMemes(): void
    {
        $js = (string) file_get_contents(self::fichier());

        foreach (['FICHE_COORDONNEES' => ScreenStatePrivacy::COORDONNEES, 'FICHE_NON_PERSONNELS' => ScreenStatePrivacy::NON_PERSONNELS] as $var => $attendu) {
            self::assertSame(1, preg_match('/var '.$var.' = \[(.*?)\];/s', $js, $m), "$var introuvable");
            preg_match_all("/'([a-z0-9_]+)'/", $m[1], $alias);
            self::assertSame($attendu, $alias[1], "$var (navigateur) et ScreenStatePrivacy (serveur) divergent");
        }

        // Les écrans de fiche : même verdict des deux côtés. `search` n'a ni
        // préfixe ni place en liste blanche, seul le chemin décide.
        $chemins = ['/s/contacts/new', '/s/contacts/edit/42', '/s/companies', '/s/companies/edit/7',
            '/s/contacts', '/s/contacts/view/42', '/s/contacts/import/new', '/s/contacts/fields/edit/3',
            '/s/segments/new', '/s/emails/edit/3'];
        $cas = [];
        foreach ($chemins as $chemin) {
            $cas[] = ['name' => 'search', 'type' => 'text', 'chemin' => $chemin, 'formulaire' => ''];
        }
        $r = $this->executer(['cas' => $cas]);
        foreach ($chemins as $i => $chemin) {
            self::assertSame(1 === preg_match(ScreenStatePrivacy::FICHE_CHEMINS, $chemin), $r['masquee'][$i], "verdict divergent sur $chemin");
        }
    }

    public function testLesSignauxSeCombinent(): void
    {
        $r = $this->executer(['cas' => [
            // Type email/tel/url : masqué même pour un alias de liste blanche.
            ['name' => 'lead[owner]', 'type' => 'tel', 'chemin' => '/s/contacts/edit/1', 'formulaire' => 'lead'],
            // Champ personnalisé de type email : masqué.
            ['name' => 'lead[email_pro]', 'type' => 'email', 'chemin' => '/s/contacts/new', 'formulaire' => 'lead'],
            // Le nom du formulaire suffit (modale sur un autre écran).
            ['name' => 'adresse_livraison', 'type' => 'text', 'chemin' => '/s/dashboard', 'formulaire' => 'company'],
            // Hors fiche, un type email ne change rien (e-mails, formulaires…).
            ['name' => 'emailform[fromAddress]', 'type' => 'email', 'chemin' => '/s/emails/new', 'formulaire' => 'emailform'],
            // leadlist[ n'est pas lead[ (écran segment).
            ['name' => 'leadlist[name]', 'type' => 'text', 'chemin' => '/s/segments/new', 'formulaire' => 'leadlist'],
            // Liste blanche sur une fiche.
            ['name' => 'lead[tags][]', 'type' => '', 'chemin' => '/s/contacts/edit/1', 'formulaire' => 'lead'],
        ]]);

        self::assertSame([true, true, true, false, false, false], $r['masquee']);
    }
}
