<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Retouche d'image au thème Sendly — maquette validée par le proprio le
 * 13/08 (décisions R-a : 6 outils en mobile ; R-b : plein écran mobile).
 * Contrat verrouillé au niveau des SOURCES (la capture montrait la modale
 * qui débordait, la barre coupée et « Apply » flottant, tout en anglais).
 */
final class BuilderRetoucheTest extends TestCase
{
    private const JS      = __DIR__.'/../../Assets/js/builder-retouche.js';
    private const SERVICE = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/library/js/builder.service.js';
    private const DIST    = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/library/js/dist/builder.js';
    private const THEME   = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/css/builder-sendly.css';

    public function testLaConfigDuPluginParleFrancaisEtLimiteLesOutils(): void
    {
        $service = (string) file_get_contents(self::SERVICE);

        self::assertStringContainsString('labelImageEditor: "Retoucher l\'image"', $service);
        self::assertStringContainsString("labelApply: 'Appliquer'", $service);
        // Décision R-a : 6 outils — les réglages fins restent au défaut.
        self::assertStringContainsString("menu: ['crop', 'rotate', 'flip', 'draw', 'text', 'filter']", $service);
        self::assertStringContainsString("menuBarPosition: 'bottom'", $service);
        // Icônes teintées à l'init (le CSS ne recolore pas le sprite tui).
        self::assertStringContainsString("'menu.normalIcon.color': '#6a7486'", $service);
        self::assertStringContainsString("'menu.activeIcon.color': '#004FFF'", $service);
    }

    public function testLeDistCommiteEmbarqueLaConfig(): void
    {
        // Le bundle Parcel est COMMITÉ : sans rebuild, la config ne part
        // jamais en prod (piège gravé depuis P0).
        $dist = (string) file_get_contents(self::DIST);

        self::assertStringContainsString("Retoucher l'image", $dist);
        self::assertStringContainsString('menuBarPosition', $dist);
    }

    public function testLeCanvasEstRecalepineAuFormatDeLaModale(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("name: 'sendly-retouche-image'", $js);
        self::assertStringContainsString("context: ['page']", $js);
        // tui fige ses dimensions à l'init (650px) : resizeEditor est le
        // SEUL levier — deux passes (l'instance naît pendant le run, le
        // bundle tui peut arriver du réseau), suivi du resize, décroché
        // à la fermeture.
        self::assertStringContainsString('inst.ui.resizeEditor', $js);
        self::assertStringContainsString("editor.on('run:tui-image-editor'", $js);
        self::assertStringContainsString("editor.on('stop:tui-image-editor'", $js);
        self::assertStringContainsString("window.addEventListener('resize', suivi)", $js);
        self::assertStringContainsString("window.removeEventListener('resize', suivi)", $js);
        self::assertStringContainsString('window.innerWidth < 768', $js);
    }

    public function testLeThemeHabilleLaModaleEtSaBarre(): void
    {
        $theme = (string) file_get_contents(self::THEME);

        // Relevés de sculpture (13/08) : la barre est en display:TABLE
        // (width:100% = minimum → flex), centrage+débordement coupe le
        // début (flex-start + margin:auto), Appliquer stylé inline (→
        // !important). Libellés FR sous les icônes.
        self::assertStringContainsString('.gjs-mode-page .gjs-mdl-dialog:has(.tui-image-editor-container)', $theme);
        self::assertStringContainsString('display: flex !important', $theme);
        self::assertStringContainsString('justify-content: flex-start !important', $theme);
        self::assertStringContainsString('margin: 0 auto !important', $theme);
        foreach (['Recadrer', 'Pivoter', 'Miroir', 'Dessiner', 'Texte', 'Filtres'] as $libelle) {
            self::assertStringContainsString('content: "'.$libelle.'"', $theme);
        }
        // Décision R-b : plein écran sous 768px, Appliquer dans la rangée
        // du titre.
        self::assertStringContainsString('position: fixed; inset: 0; width: 100vw !important', $theme);
        self::assertStringContainsString('position: fixed !important; top: 12px !important; right: 48px !important', $theme);
    }
}
