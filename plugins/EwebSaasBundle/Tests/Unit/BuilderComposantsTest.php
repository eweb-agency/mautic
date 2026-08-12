<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P2 de la refonte de l'éditeur : l'onglet Composants et la barre haute.
 * Contrat gravé au niveau des SOURCES (même approche que BuilderShellTest),
 * chaque assertion correspond à un comportement VALIDÉ en direct le 10/08.
 */
final class BuilderComposantsTest extends TestCase
{
    private const JS    = __DIR__.'/../../Assets/js/builder-composants.js';
    private const THEME = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/css/builder-sendly.css';

    public function testLePluginEstScopePageViaLAccrocheOfficielle(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('window.MauticGrapesJsPlugins', $js);
        self::assertStringContainsString("name: 'sendly-composants'", $js);
        self::assertStringContainsString("context: ['page']", $js, 'sans ce contexte, l editeur d e-mails heriterait du remap');
    }

    public function testLInventaireMaquetteEstCompletEtFrancais(): void
    {
        $js = (string) file_get_contents(self::JS);

        // Les 20 tuiles validées sur maquette, en français.
        foreach (['Texte', 'Citation', 'Lien', 'Lien encadré', 'Image', 'Vidéo',
            'Section de texte', 'Bouton', 'Carte', 'Compte à rebours', 'Code',
            'Barre de navigation', 'Séparateur', 'Réseaux sociaux', 'Formulaire',
            'Assistant IA', '1 colonne', '2 colonnes', '3 colonnes', '30 / 70'] as $libelle) {
            self::assertStringContainsString("'".$libelle."'", $js, 'tuile absente : '.$libelle);
        }

        self::assertStringContainsString('BASIQUE', $js);
        self::assertStringContainsString('MISE EN PAGE', $js);
    }

    public function testZeroRegressionLesBlocsExistantsSontTousRemappes(): void
    {
        // Principe gravé 10/08 : renommer et ranger, ne JAMAIS retirer un bloc.
        $js = (string) file_get_contents(self::JS);

        foreach (['text', 'quote', 'link', 'link-block', 'image', 'video',
            'map', 'countdown', 'custom-code', 'navbar',
            'column1', 'column2', 'column3', 'column3-7'] as $id) {
            self::assertStringContainsString("'".$id."'", $js, 'bloc existant non mappé : '.$id);
        }

        // Le bouton du thème et « Text section » ont des ids VARIABLES :
        // le rattrapage se fait par libellé.
        self::assertStringContainsString('button|bouton', $js);
        self::assertStringContainsString('text section', $js);
    }

    public function testEnregistrerSauveSansFermer(): void
    {
        // Demande proprio 12/08 : « le builder se ferme au moment du clic,
        // il faut que je réouvre pour continuer ». Enregistrer = même VRAI
        // enregistrement que Terminer (proxy apply-form), générateur OUVERT,
        // retour visuel sur le bouton.
        $js    = (string) file_get_contents(self::JS);
        $debut = strpos($js, "id: 'sendly-enregistrer'");
        $fin   = strpos($js, "id: 'sendly-terminer'");

        self::assertNotFalse($debut);
        self::assertNotFalse($fin);
        $bloc = substr($js, $debut, $fin - $debut);
        self::assertStringContainsString("'sendly-apply-proxy'", $bloc);
        self::assertStringContainsString('Enregistrement…', $bloc);
        self::assertStringContainsString('Enregistré ✓', $bloc);
        self::assertStringNotContainsString('html-close', $bloc, 'Enregistrer ne doit JAMAIS fermer');
    }

    public function testTerminerEnregistreVraimentPuisFerme(): void
    {
        // Recette proprio 12/08 : « la page ne s'enregistre pas ». La
        // commande historique `mautic-editor-page-html-apply` n'existe
        // NULLE PART (runCommand sur un nom inconnu = silence) — seule la
        // fermeture rendait le contenu au formulaire, rien ne partait en
        // base. Le VRAI enregistrement est `preset-mautic:apply-form`
        // (textarea + POST), déclenché par le bouton PROXY (la commande
        // exige un bouton sender), la fermeture suit l'événement stop.
        // Prouvé en session : marqueur retrouvé dans l'aperçu SERVI.
        $js = (string) file_get_contents(self::JS);

        self::assertStringNotContainsString("runCommand('mautic-editor-page-html-apply')", $js, 'commande fantôme : elle n existe nulle part');
        $stop  = strpos($js, "once('stop:preset-mautic:apply-form'");
        $close = strpos($js, "runCommand('mautic-editor-page-html-close')", (int) $stop);
        self::assertNotFalse($stop, 'Terminer doit attendre la fin du VRAI enregistrement');
        self::assertNotFalse($close, 'Terminer doit fermer APRES l enregistrement');
    }

    public function testLesContoursSontCoupesApresRetraitDuToggle(): void
    {
        // Le préréglage ACTIVE sw-visibility au chargement puis on retire le
        // bouton : sans stopCommand, les pointillés resteraient affichés sans
        // plus aucun interrupteur (case « Contours » dans Options en P3).
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("stopCommand('sw-visibility')", $js);
        foreach (['fullscreen', 'code-edit', 'ai-generate'] as $bouton) {
            self::assertStringContainsString($bouton, $js, 'bouton à retirer absent du js : '.$bouton);
        }
    }

    public function testLeThemeP2CouvreTuilesEtBarreHaute(): void
    {
        $theme = (string) file_get_contents(self::THEME);

        // Les <rect> Lucide se remplissaient en blanc plein : GrapesJS pose
        // fill:currentColor en CSS, qui BAT l'attribut fill="none".
        self::assertStringContainsString('.gjs-mode-page .gjs-block svg { fill: none; }', $theme);
        // Les conteneurs DOM des catégories héritées survivent au remap.
        self::assertStringContainsString(':not(:has(.gjs-block))', $theme);
        // Onglets texte + boutons Effacer/Annuler/Terminer.
        self::assertStringContainsString('sendly-tab', $theme);
        self::assertStringContainsString('sendly-btn-ghost', $theme);
        self::assertStringContainsString('sendly-btn-primary', $theme);
        // La VALEUR du marqueur avance a chaque phase (p2 -> p3 -> ...) :
        // ce test verifie des acquis P2 qui survivent aux phases suivantes,
        // il ne l'epingle donc pas — BuilderStylesTest porte la valeur courante.
        self::assertStringContainsString('--sendly-builder-theme:', $theme);
    }

    public function testLIconeIaSurvitAuFillNoneGenerique(): void
    {
        // Seule icône PLEINE par dessein : son style inline doit gagner sur
        // la règle .gjs-block svg { fill: none; } du thème.
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('style=\\"fill:#fff\\"', $js);
    }

    public function testLeFantomeDuPanelCommandsEstRetireEtLApercuSArme(): void
    {
        // Recette proprio 12/08 : (1) un bouton SANS id traînait dans le
        // panel commands — 34 px de vide cliquable à gauche du Undo ;
        // (2) l'aperçu restait mort sur une page neuve (le preset attend un
        // « Appliquer » que notre barre n'a plus). Le clic sur l'aperçu
        // désactivé applique via un bouton PROXY (la commande apply-form
        // plante en appel direct — sender.set is not a function, même piège
        // que le mode Code) puis l'aperçu s'ouvre au ré-armement.
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("if (!b.get('id')) { cmds.get('buttons').remove(b); }", $js);
        self::assertStringContainsString("id: 'sendly-apply-proxy'", $js);
        self::assertStringContainsString("command: 'preset-mautic:apply-form'", $js);
        self::assertStringContainsString('stop:preset-mautic:apply-form', $js);
        self::assertStringContainsString('btn-views-Preview', $js);
    }

    public function testLaPiluleDAppareilsNeBasculeJamaisEtSeResynchronise(): void
    {
        // Recette proprio 12/08 : les boutons d'appareil du preset sont a
        // BASCULE — bouton reste actif pendant que l'appareil change par
        // ailleurs -> le clic suivant l'ETEINT (stop) au lieu d'executer,
        // un clic sur deux ne faisait rien (reproduit en clic reel).
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("b.set('togglable', false)", $js);
        self::assertStringContainsString("editor.on('change:device'", $js);
        self::assertStringContainsString("b.set('active', actif, { silent: true })", $js);
        self::assertStringContainsString("classList.toggle('gjs-pn-active', actif)", $js);
    }

    public function testLaTuileIaNApparaitQueSiLeCopiloteEstActif(): void
    {
        // Relevé en réel (P5, tenant sans clé) : sans copilote il n'y a NI
        // config NI panneau — une tuile qui n'ouvre rien serait une promesse
        // cassée. Même gating 3 niveaux que les boutons IA des e-mails.
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('if (window.SendlyAiConfig && window.SendlyAiConfig.enabled) {', $js);
        $garde = strpos($js, 'window.SendlyAiConfig && window.SendlyAiConfig.enabled');
        $tuile = strpos($js, "bm.add('sendly-ia'");
        self::assertNotFalse($garde);
        self::assertNotFalse($tuile);
        self::assertLessThan($tuile, $garde, 'la garde doit precede l ajout de la tuile IA');
    }
}
