<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6 du chantier D : la modale « Choisir une image » (gestionnaire d'images)
 * au thème Sendly, en français, sans fuite de marque. Sculptée en direct le
 * 12/08 et validée par le proprio (« Bien ») avec une retouche de bordure.
 */
final class BuilderModalesTest extends TestCase
{
    private const THEME      = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/css/builder-sendly.css';
    private const COMPOSANTS = __DIR__.'/../../Assets/js/builder-composants.js';
    private const ICONE      = __DIR__.'/../../../../app/assets/images/apple-touch-icon.png';

    public function testLaModaleEstAuThemeSendly(): void
    {
        $css = (string) file_get_contents(self::THEME);

        foreach (['.gjs-mdl-dialog', '.gjs-mdl-title', '.gjs-am-file-uploader', '.gjs-am-asset'] as $piece) {
            self::assertStringContainsString('.gjs-mode-page '.$piece, $css);
        }
        // Retour proprio 12/08 (capture) : le cadre pointillé d'origine vit
        // sur le FORM intérieur — le styler sur l'enveloppe DOUBLAIT la
        // bordure. L'enveloppe est nue, le form porte l'unique cadre.
        self::assertStringContainsString('.gjs-am-file-uploader { border: none', $css);
        self::assertStringContainsString('.gjs-am-file-uploader > form { border: 2px dashed', $css);
    }

    public function testLeGestionnaireDImagesParleFrancais(): void
    {
        $js = (string) file_get_contents(self::COMPOSANTS);

        self::assertStringContainsString('editor.I18n.addMessages', $js);
        self::assertStringContainsString("modalTitle: 'Choisir une image'", $js);
        self::assertStringContainsString('Déposez vos fichiers ici ou cliquez pour téléverser', $js);
        self::assertStringContainsString("addButton: \"Ajouter l'image\"", $js);
    }

    public function testLIconeTactileEstCelleDeSendly(): void
    {
        // B-02 (fuite repérée par le proprio 11/08) : apple-touch-icon.png
        // portait le logo du MOTEUR — servie aux navigateurs des clients
        // (épingles iOS, favoris) ET listée dans le gestionnaire d'images.
        // Remplacée par l'icône OFFICIELLE du portail Sendly (180x180).
        self::assertFileExists(self::ICONE);
        self::assertSame(
            'e5d29f029b26571a1e069853cd9f662a19b757a7',
            sha1_file(self::ICONE),
            "l'icône tactile doit rester celle de Sendly (B-02 : jamais le logo du moteur)"
        );
    }

    public function testLeMarqueurDeThemeEstEnP6(): void
    {
        self::assertStringContainsString("--sendly-builder-theme: 'p6'", (string) file_get_contents(self::THEME));
    }
}
