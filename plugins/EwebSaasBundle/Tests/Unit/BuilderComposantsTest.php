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

    public function testTerminerAppliquePuisFerme(): void
    {
        // Arbitrage 10/08 : Terminer = appliquer + fermer. L'apply écrit le
        // textarea en SYNCHRONE avant son POST (buttonApply.command.js) :
        // fermer juste après est sûr — mais l'ordre est vital.
        $js = (string) file_get_contents(self::JS);

        $apply = strpos($js, "runCommand('mautic-editor-page-html-apply')");
        $close = strpos($js, "runCommand('mautic-editor-page-html-close')", (int) $apply);
        self::assertNotFalse($apply, 'Terminer doit appliquer');
        self::assertNotFalse($close, 'Terminer doit fermer APRES avoir appliqué');
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
        self::assertStringContainsString("--sendly-builder-theme: 'p2'", $theme);
    }

    public function testLIconeIaSurvitAuFillNoneGenerique(): void
    {
        // Seule icône PLEINE par dessein : son style inline doit gagner sur
        // la règle .gjs-block svg { fill: none; } du thème.
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString('style=\\"fill:#fff\\"', $js);
    }
}
