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
