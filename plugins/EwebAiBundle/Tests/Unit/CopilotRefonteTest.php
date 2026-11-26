<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;

/**
 * La refonte de Sendly Copilot, direction « Pastille » (validée par le
 * propriétaire le 26/11/2026).
 *
 * Ce que ces tests verrouillent :
 *  - le bouton au repos est un rond, l'icône seule ; la pastille libellée
 *    n'existe que pour un état ou un reçu ;
 *  - chaque raccourci tient sur UNE ligne (36 caractères au plus) tout en
 *    envoyant la demande complète au modèle ;
 *  - un reçu garde son texte en clair (« ✓ titre », « ✗ titre : motif ») :
 *    c'est ce que lisent un lecteur d'écran et l'historique ;
 *  - la durée affichée vient du barème du SERVEUR, jamais d'un chiffre posé
 *    dans l'interface ;
 *  - « Annuler » sur un segment défait aussi le nom et la description ;
 *  - dans l'éditeur, seul le bouton au repos se masque.
 *
 * Les fonctions pures s'EXÉCUTENT dans Node (présent dans le job PHPUnit de
 * la CI), sur ai-assistant.js tel qu'il est livré.
 */
final class CopilotRefonteTest extends TestCase
{
    private const HARNAIS = <<<'JS'
'use strict';
const fs = require('fs');
const vm = require('vm');
const fichier = process.argv[1];
const sc = JSON.parse(process.argv[2]);
const rien = function () {};
const vide = { length: 0, each: rien, on: rien, find: function () { return vide; }, filter: function () { return vide; } };
function mQuery() { return vide; }
mQuery.ajax = function () { return { done: rien }; };
mQuery.extend = function () { return Object.assign.apply(Object, arguments); };
const location = { pathname: '/s/dashboard', search: '' };
const bac = {
  window: { SendlyAiConfig: { enabled: true, assistEndpoint: '/s/ai/assist', bareme: sc.bareme || null }, location: location },
  document: { documentElement: { lang: 'fr' }, addEventListener: rien, getElementById: function () { return null; }, querySelector: function () { return null; },
    createElement: function () { return { innerHTML: '' }; } },
  Mautic: { translate: function (k) { return k; } },
  mQuery: mQuery,
  setTimeout: rien,
  sessionStorage: { setItem: rien }
};
vm.createContext(bac);
vm.runInContext(fs.readFileSync(fichier, 'utf8'), bac, { filename: fichier });
const SA = bac.window.SendlyAssistant;
const aide = bac.window.SendlyAssistantContexts.filter(function (c) { return c.id === 'help'; })[0];
const sortie = {};

if (sc.chemins) {
  sortie.raccourcis = {};
  sc.chemins.forEach(function (chemin) {
    location.pathname = chemin;
    sortie.raccourcis[chemin] = { titre: aide.title(), accueil: aide.hero(), liste: aide.shortcuts() };
  });
}
if (sc.recus) { sortie.recus = sc.recus.map(function (o) { return SA.recu(o); }); }
if (sc.lignes) { sortie.lignes = sc.lignes.map(function (l) { return SA.ligne(l[0], l[1] || null); }); }
if (sc.durees) { sortie.durees = sc.durees.map(function (d) { return SA.duree(d[0], d[1]); }); }
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

    private static function source(string $chemin): string
    {
        $fichier = __DIR__.'/../../'.$chemin;
        self::assertFileExists($fichier);

        return (string) file_get_contents($fichier);
    }

    private static function texte(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES);
    }

    /** Le barème du serveur, tel que l'interface le reçoit. */
    private function bareme(): AiCopilotService
    {
        return (new \ReflectionClass(AiCopilotService::class))->newInstanceWithoutConstructor();
    }

    // ── Le bouton et la carte ─────────────────────────────────────────────

    public function testAuReposLeBoutonEstUnRondSansLibelle(): void
    {
        $js = self::source('Assets/js/ai-assistant.js');

        // L'icône seule sur un fond qui vit (retour du propriétaire, 25/11) :
        // au repos, aucun texte dans le bouton.
        self::assertStringContainsString("fab.innerHTML = '<span class=\"sendly-assist-vivant\"></span>' + eclat(24);", $js);
        self::assertStringContainsString("fab.classList.toggle('sendly-assist-rond', p.mode === 'repos');", $js);
        self::assertStringContainsString('conic-gradient(from 0deg', $js);
        // Un <div role="button"> : en pastille il porte un vrai bouton.
        self::assertStringContainsString("fab.setAttribute('role', 'button');", $js);
        self::assertStringContainsString("fab.setAttribute('tabindex', '0');", $js);
        // Le nom accessible reste dit, raccourci clavier compris.
        self::assertStringContainsString("fab.setAttribute('aria-label', label + ' (⌘J)');", $js);
    }

    public function testLaCarteSAncreEnBasADroiteEtLaisseLEcranVisible(): void
    {
        $js = self::source('Assets/js/ai-assistant.js');

        self::assertStringContainsString('max-height:calc(100vh - 72px)', $js);
        self::assertStringNotContainsString("el.style.top = ", $js, 'plus de colonne ancrée sous la barre haute');
        // Téléphone : feuille du bas, champ en 16 px, cibles de 44 px.
        self::assertStringContainsString('@media (max-width:600px){', $js);
        self::assertStringContainsString('border-radius:24px 24px 0 0', $js);
        self::assertStringContainsString("font:400 16px ' + POLICE", $js);
        self::assertStringContainsString("' .sendly-assist-iconbtn{width:44px;height:44px}'", $js);
        // Accessibilité : dialogue nommé, Échap, conversation annoncée, mouvement réduit.
        self::assertStringContainsString("el.setAttribute('role', 'dialog');", $js);
        self::assertStringContainsString("if (e.key === 'Escape') {", $js);
        self::assertStringContainsString('aria-live="polite"', $js);
        self::assertStringContainsString('@media (prefers-reduced-motion:reduce){', $js);
    }

    public function testLaPoliceDeLaCarteEstEmbarqueeDansLePlugin(): void
    {
        $js = self::source('Assets/js/ai-assistant.js');

        foreach (['Light', 'Regular', 'Medium'] as $graisse) {
            self::assertStringContainsString('/fonts/Helvena_'.$graisse.'.woff2', $js);
            self::assertFileExists(__DIR__.'/../../Assets/fonts/Helvena_'.$graisse.'.woff2');
        }
        self::assertFileExists(__DIR__.'/../../Assets/img/copilot-vague.jpg');
        self::assertFileExists(__DIR__.'/../../Assets/img/copilot-grain.jpg');
    }

    // ── Raccourcis ────────────────────────────────────────────────────────

    public function testChaqueRaccourciTientSurUneLigneEtEnvoieLaDemandeComplete(): void
    {
        $chemins = ['/s/dashboard', '/s/contacts', '/s/companies', '/s/segments', '/s/campaigns', '/s/emails/edit/7', '/s/sms',
            '/s/forms', '/s/pages', '/s/assets', '/s/dwc', '/s/points', '/s/stages', '/s/reports'];
        $r = $this->executer(['chemins' => $chemins]);

        $types = ['navigate', 'create_segment', 'create_email', 'create_form', 'create_report', 'create_landing_page', 'schedule_email', 'create_ab_test'];
        foreach ($chemins as $chemin) {
            $ecran = $r['raccourcis'][$chemin];
            self::assertNotEmpty($ecran['liste'], $chemin);
            foreach ($ecran['liste'] as $raccourci) {
                self::assertIsArray($raccourci);
                [$libelle, $demande, $type] = $raccourci;
                self::assertLessThanOrEqual(36, mb_strlen($libelle), '« '.$libelle.' » ne tient pas sur une ligne de la carte');
                self::assertContains($type, $types, $libelle);
                // La demande envoyée au modèle reste une phrase complète, à l'impératif.
                $envoyee = '' !== $demande ? $demande : $libelle;
                self::assertMatchesRegularExpression('/^(Ouvrir|Créer|Rédiger|Programmer) /u', $envoyee, $libelle);
            }
        }
        self::assertSame('Sendly Copilot · Segments', $r['raccourcis']['/s/segments']['titre']);
        self::assertSame("Dites ce qu’il faut faire.\nCopilot s’en occupe.", $r['raccourcis']['/s/dashboard']['accueil']);
    }

    // ── Reçus ─────────────────────────────────────────────────────────────

    public function testUnRecuGardeSonTexteEnClair(): void
    {
        $r = $this->executer(['lignes' => [
            ['✓ 2 champs remplis'],
            ['✗ La création n’a pas abouti : e-mail introuvable (« Relance panier »).'],
            ['Création en cours (en brouillon), ouverture de l’écran…'],
            ['→ Ouverture de l’écran…'],
            ['Rien n’a été exécuté : vous avez changé d’écran pendant ma réponse.'],
            ['✗ Rien n’a été programmé : vous avez choisi de ne rien faire.', ['etat' => 'annule']],
        ]]);
        [$fait, $echec, $creation, $ouverture, $remarque, $refus] = $r['lignes'];

        self::assertSame('✓ 2 champs remplis', self::texte($fait));
        self::assertStringContainsString('class="sendly-assist-recu sendly-assist-fait"', $fait);
        // Titre et motif : deux éléments à l'écran, une seule phrase à la lecture.
        self::assertSame('✗ La création n’a pas abouti : e-mail introuvable (« Relance panier »).', self::texte($echec));
        self::assertStringContainsString('<span class="sendly-assist-recu-titre">La création n’a pas abouti</span>', $echec);
        self::assertStringContainsString('<span class="sendly-assist-motif">e-mail introuvable (« Relance panier »).</span>', $echec);
        self::assertStringContainsString('sendly-assist-echec', $echec);
        // Une attente n'est jamais un reçu : aucun signe de réussite d'avance.
        foreach ([$creation, $ouverture] as $attente) {
            self::assertStringStartsWith('<div class="sendly-assist-mut sendly-assist-encours">', $attente);
            self::assertStringNotContainsString('sendly-assist-recu', $attente);
        }
        self::assertSame('<div class="sendly-assist-mut">Rien n’a été exécuté : vous avez changé d’écran pendant ma réponse.</div>', $remarque);
        self::assertStringContainsString('sendly-assist-recu sendly-assist-annule', $refus);
        self::assertStringNotContainsString('✗', self::texte($refus));
    }

    public function testUnRecuEchappeToutCeQuIlAffiche(): void
    {
        $r = $this->executer(['recus' => [[
            'titre'  => '<img src=x onerror=alert(1)>',
            'motif'  => '<script>x</script>',
            'lignes' => [['<b>Nom</b>', '"><svg onload=alert(1)>']],
            'note'   => '<i>note</i>',
        ]]]);

        $html = $r['recus'][0];
        foreach (['<img', '<script', '<svg', '<b>', '<i>'] as $balise) {
            self::assertStringNotContainsString($balise, $html);
        }
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testLeRecuAnnulablePorteSonMarqueurEtLEchecNePorteJamaisDeDuree(): void
    {
        $bareme = $this->bareme()->creditBareme();
        $r      = $this->executer(['bareme' => $bareme, 'recus' => [
            ['titre' => 'Segment préparé', 'secondes' => 600, 'annulable' => true, 'lignes' => [['Critère', 'Ville est vide']]],
            ['etat'  => 'echec', 'titre' => 'Campagne non créée', 'motif' => 'e-mail introuvable', 'secondes' => 1200],
            ['titre' => 'Champ rempli', 'secondes' => 30],
        ]]);
        [$fait, $echec, $court] = $r['recus'];

        self::assertStringContainsString('sendly-assist-recu sendly-assist-fait sendly-assist-annulable', $fait);
        self::assertStringContainsString('<!--sendly-annuler-->', $fait, 'la coquille y pose le bouton « Annuler », ou la trace de l’annulation');
        self::assertStringContainsString('<dt>Critère</dt><dd>Ville est vide</dd>', $fait);
        self::assertStringContainsString("≈\u{a0}10\u{a0}min", self::texte($fait));
        self::assertStringNotContainsString('sendly-assist-temps', $echec, 'un échec ne fait rien gagner');
        self::assertStringNotContainsString('sendly-assist-temps', $court, 'sous la minute, aucune durée n’est affichée');
    }

    // ── Temps gagné : le barème du serveur, rien d'autre ──────────────────

    public function testLaDureeAfficheeVientDuBaremeDuServeur(): void
    {
        $service = $this->bareme();
        $bareme  = $service->creditBareme();

        // L'interface reçoit exactement ce que le serveur crédite.
        foreach ($bareme as $type => $secondes) {
            self::assertSame($secondes, $service->creditSeconds($type, 1), $type);
        }
        self::assertStringContainsString("'bareme'           => \$this->copilot->creditBareme(),", self::source('EventSubscriber/AiConfigAssetsSubscriber.php'));

        $avec = $this->executer(['bareme' => $bareme, 'durees' => [
            ['create_segment', 1], ['create_email', 1], ['create_landing_page', 1], ['fill_field', 1], ['fill_field', 2], ['inconnu', 1],
        ]]);
        self::assertSame(["10\u{a0}min", "30\u{a0}min", "45\u{a0}min", '', "1\u{a0}min", ''], $avec['durees']);

        // Sans barème (onglet resté sur une ancienne configuration) : aucune
        // durée, plutôt qu'un chiffre inventé par l'interface.
        $sans = $this->executer(['durees' => [['create_segment', 1], ['create_email', 1]]]);
        self::assertSame(['', ''], $sans['durees']);

        $js = self::source('Assets/js/ai-assistant.js');
        self::assertStringContainsString('c.bareme[type]', $js);
    }

    // ── Conversation conservée, reçu à l'arrivée, arrêt ───────────────────

    public function testLaConversationSuitDUnEcranALAutre(): void
    {
        $js = self::source('Assets/js/ai-assistant.js');

        self::assertStringContainsString("var CLE_MEMOIRE = 'sendlyAssistConv';", $js);
        self::assertStringContainsString('Date.now() - memo.ts > VIE_MEMOIRE', $js, 'durée de vie courte, comme les relais de création');
        // Ni l'annulation ni une confirmation en attente ne survivent à un rechargement.
        self::assertStringContainsString("return { role: 'ia', html: html, plain: turn.plain || '', result: turn.result || '' };", $js);
        self::assertStringContainsString('Confirmation interrompue par le changement d’écran. Redemandez-la si besoin.', $js);
        // Une navigation rend son reçu à l'arrivée ; les 18 écrans ont leur libellé.
        self::assertStringContainsString("if ('navigate' === lancee.quoi) { promettreArrivee(NAV_ARRIVEES[lancee.cible] || 'Écran ouvert', ''); }", $js);
        preg_match('/var NAV_CIBLES = \{(.*?)\};/s', $js, $cibles);
        preg_match('/var NAV_ARRIVEES = \{(.*?)\};/s', $js, $arrivees);
        preg_match_all('/(\w+): \'\//', $cibles[1], $nomsCibles);
        preg_match_all('/(\w+): \'/', $arrivees[1], $nomsArrivees);
        self::assertCount(18, $nomsCibles[1]);
        self::assertSame($nomsCibles[1], $nomsArrivees[1], 'chaque écran ouvrable a son reçu d’arrivée');
        // Une annulation ne vaut que sur l'écran où le geste a été fait.
        self::assertStringContainsString('convStore[id].forEach(function (turn) { turn.undoable = false; });', $js);
    }

    public function testArreterIgnoreLaReponseTardive(): void
    {
        $js = self::source('Assets/js/ai-assistant.js');

        self::assertStringContainsString('enCours.arrete = true;', $js);
        self::assertStringContainsString("if (typeof api.arrete === 'function' && api.arrete()) { return; }", $js, 'rien de la réponse n’est joué à l’écran');
        self::assertStringContainsString('if (!jeton.arrete) { maConv.push(turn); memoriser(); }', $js);
        self::assertStringContainsString("if (typeof api.arrete === 'function' && api.arrete()) { return; }", self::source('Assets/js/ai-segment.js'));
    }

    // ── Segment : « Annuler » tient sa promesse ───────────────────────────

    public function testAnnulerUnSegmentDefaitAussiLeNomEtLaDescription(): void
    {
        $js = self::source('Assets/js/ai-segment.js');

        // Constat du 12/11 sur l'espace : les critères partaient, le nom et
        // la description posés par le Copilot restaient.
        self::assertStringContainsString('if (trace.nom || trace.desc) { detailsParTour[turnId] = trace; }', $js);
        self::assertStringContainsString('annulerDetails(turnId);', $js);
        // Sans écraser une valeur reprise à la main entre-temps.
        self::assertStringContainsString('if ($nom.length && $nom.val() === d.nom.pose) {', $js);
        self::assertStringContainsString("if (\$desc.length && String(\$desc.val() || '') === d.desc.pose) {", $js);
        // Un tour qui n'a fait que nommer est annulable, lui aussi.
        self::assertStringContainsString('var annulable = !!(result.applied.length || retires.length || nommes.nom || nommes.description);', $js);
        // Le résumé est un reçu, et la page s'allume.
        self::assertStringContainsString('window.SendlyAssistant.recu({', $js);
        self::assertStringContainsString('allumerTour(turnId, nommes);', $js);
        self::assertStringNotContainsString('sendly-assist-fait', $js, 'plus de ligne verte composée à la main');
    }

    // ── Éditeur : la pastille compte les étapes, le rond reste masqué ─────

    public function testDansLEditeurSeulLeBoutonAuReposSeMasque(): void
    {
        $coquille = self::source('Assets/js/ai-assistant.js');
        $editeur  = (string) file_get_contents(__DIR__.'/../../../EwebSaasBundle/Assets/js/builder-shell.js');

        self::assertStringContainsString("document.body.classList.toggle('sendly-builder-ouvert', !!hidden);", $editeur);
        self::assertStringNotContainsString("fab.style.display = hidden ? 'none' : '';", $editeur);
        self::assertStringContainsString("'body.sendly-builder-ouvert ' + F + '.sendly-assist-rond{display:none}'", $coquille);
        // La tuile de la bibliothèque reste l'unique entrée : ni clic ni ⌘J n'ouvrent la carte.
        self::assertSame(2, substr_count($coquille, 'editeurOuvert()) {'));

        $email = self::source('Assets/js/ai-email-builder.js');
        foreach (['etape(2);', 'etape(3);', 'etape(4);', "etape(5, 'Rédaction du corps');"] as $annonce) {
            self::assertStringContainsString($annonce, $email);
        }
        self::assertStringContainsString("window.SendlyAssistant.termine('E-mail prêt, en brouillon', { type: 'create_email', etapes: ETAPES });", $email);
        self::assertStringContainsString('repos();', $email, 'un échec ne laisse pas la pastille sur une étape');
    }
}
