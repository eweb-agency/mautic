<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P0 de la refonte de l'éditeur (chantier D) : les fondations qui protègent
 * tout le reste. Surfaces JS agrégées sans harnais de DOM : contrat gravé au
 * niveau des SOURCES, comme PortalMenuTest.
 */
final class BuilderShellTest extends TestCase
{
    private const SHELL      = __DIR__.'/../../Assets/js/builder-shell.js';
    private const SUBSCRIBER = __DIR__.'/../../../GrapesJsBuilderBundle/EventSubscriber/AssetsSubscriber.php';
    private const THEME      = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/css/builder-sendly.css';
    private const FR         = __DIR__.'/../../../GrapesJsBuilderBundle/Translations/fr/javascript.ini';
    private const EN         = __DIR__.'/../../../GrapesJsBuilderBundle/Translations/en_US/javascript.ini';

    public function testLaClasseDeModeCloisonneLesEditeurs(): void
    {
        // Le namespace .gjs-* est GLOBAL aux trois éditeurs : sans la classe
        // de mode, tout style de la refonte landing page fuirait sur
        // l'éditeur d'e-mails.
        $js = (string) file_get_contents(self::SHELL);

        self::assertStringContainsString("'builder:show'", $js);
        self::assertStringContainsString('gjs-mode-page', $js);
        self::assertStringContainsString('gjs-mode-email', $js);
        self::assertStringContainsString('form[name="page"]', $js, 'le mode se detecte par le formulaire hote');
    }

    public function testLeBoutonIaFlottantSeMasqueDansLEditeur(): void
    {
        // Décision proprio 10/08 : la tuile « Assistant IA » est l'unique
        // entrée IA de l'éditeur ; le bouton flottant revient à la fermeture.
        $js = (string) file_get_contents(self::SHELL);

        self::assertStringContainsString('sendly-assist-fab', $js);
        self::assertStringContainsString("'builder:hide'", $js);
    }

    public function testLeThemeEstServiHorsBundleEtScope(): void
    {
        // Le canal d'itération rapide : CSS servi en direct (pas de npm run
        // build), et chaque règle scopée .gjs-mode-page.
        $subscriber = (string) file_get_contents(self::SUBSCRIBER);
        self::assertStringContainsString('builder-sendly.css', $subscriber);

        $theme = (string) file_get_contents(self::THEME);
        self::assertStringContainsString('.gjs-mode-page', $theme);

        // CLOISONNEMENT MÉCANIQUE : tout sélecteur qui touche au namespace
        // global .gjs-* doit porter le scope de mode — une seule règle nue
        // fuirait sur l'éditeur d'e-mails.
        foreach (explode("\n", $theme) as $i => $line) {
            if (str_contains($line, '{') && str_contains($line, '.gjs-')) {
                self::assertStringContainsString('.gjs-mode-page', $line, sprintf('ligne %d non scopée : %s', $i + 1, trim($line)));
            }
        }
    }

    public function testLeThemeP1PorteLaGeometrieEtLaPaletteDeLaMaquette(): void
    {
        // Jetons de la maquette validée (10/08) : panneau GAUCHE 340px,
        // barre haute 56px, marque #004FFF, tuiles navy #16233b, canvas
        // #eceff4 — et le plein écran retiré (décision proprio).
        $theme = (string) file_get_contents(self::THEME);

        self::assertStringContainsString('--gjs-left-width: 340px', $theme);
        self::assertStringContainsString('--gjs-canvas-top: 56px', $theme);
        self::assertStringContainsString('#004FFF', $theme);
        self::assertStringContainsString('#16233b', $theme);
        self::assertStringContainsString('#eceff4', $theme);
        self::assertStringContainsString('[title="Fullscreen"] { display: none; }', $theme);
        // Le wrapper de frame a left/top EN INLINE et le calque d'outils vit
        // dans le repère du CANVAS : ni left/translateX NI marge sur le
        // wrapper (la marge débordait à droite sur écran large et
        // désalignait les outils — défauts proprio 11/08). La gouttière est
        // portée par l'INSET du canvas, relatif donc valable à toute largeur.
        self::assertStringNotContainsString('.gjs-frame-wrapper { left', $theme);
        self::assertStringNotContainsString('.gjs-frame-wrapper { margin: 22px', $theme);
        self::assertStringContainsString('left: calc(var(--gjs-left-width) + 22px)', $theme);
        self::assertStringContainsString('width: calc(100% - var(--gjs-left-width) - 44px)', $theme);
        // Et l'overlay de sélection ne survit pas à un changement d'appareil.
        $styles = (string) file_get_contents(__DIR__.'/../../Assets/js/builder-styles.js');
        self::assertStringContainsString("editor.on('change:device'", $styles);
    }

    public function testLesTraductionsDeLEditeurSontCompletes(): void
    {
        // Écarts relevés au contre-audit 10/08 : 7 clés absentes du fr
        // (boutons Appliquer/Aperçu affichaient la clé brute), 1 de l'en_US.
        $fr = (string) file_get_contents(self::FR);
        foreach (['builder.warning.code_mode', 'panelsViewsCommandModalTitleError',
            'panelsViewsButtonsApplyTitle', 'buttons.buttonPreview.title',
            'buttons.buttonPreview.titleDisabled', 'components.names.oneColumn',
            'components.names.twoColumn', 'components.names.threeColumn'] as $key) {
            self::assertStringContainsString($key, $fr, "cle fr manquante : $key");
        }

        $en = (string) file_get_contents(self::EN);
        self::assertStringContainsString('assetManager.noAssets', $en);
    }
}
