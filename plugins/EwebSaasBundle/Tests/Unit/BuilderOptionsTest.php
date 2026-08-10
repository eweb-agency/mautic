<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P3b de la refonte de l'éditeur (chantier D) : l'onglet « Options ».
 * Contrat gravé au niveau des SOURCES, chaque assertion = un piège ou une
 * décision constatés en direct le 10-11/08.
 */
final class BuilderOptionsTest extends TestCase
{
    private const OPTIONS = __DIR__.'/../../Assets/js/builder-options.js';
    private const THEME   = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/css/builder-sendly.css';

    public function testLOngletCouvreToutesLesSectionsDecidees(): void
    {
        $js = (string) file_get_contents(self::OPTIONS);

        foreach (['Page', 'Publication', 'Redirection', 'Scripts', 'Éditeur'] as $section) {
            self::assertStringContainsString('sendly-opt-titre">'.$section.'<', $js, "section manquante : $section");
        }
        foreach (['title', 'language', 'category', 'metaDescription', 'publishUp', 'publishDown',
            'redirectType', 'redirectUrl', 'headScript', 'footerScript'] as $champ) {
            self::assertStringContainsString('data-nat="'.$champ.'"', $js, "champ non synchronisé : $champ");
        }
        foreach (['noIndex', 'isPublished'] as $booleen) {
            self::assertStringContainsString('data-nat-radio="'.$booleen.'"', $js, "booléen manquant : $booleen");
        }
    }

    public function testLaSynchroPasseParLeFormulaireNatifSansAucunPost(): void
    {
        // Décision structurante : l'onglet n'invente AUCUN canal — il écrit
        // dans les champs du formulaire natif, et les valeurs partent avec
        // « Terminer » comme une saisie manuelle. Zéro requête réseau.
        $js = (string) file_get_contents(self::OPTIONS);

        self::assertStringContainsString("form[name=\"page\"] [name=\"page[' + nom + ']\"]", $js);
        self::assertStringNotContainsString('fetch(', $js, "l'onglet Options ne fait AUCUN appel réseau");
        self::assertStringNotContainsString('XMLHttpRequest', $js);
        // Les booléens natifs = paire de radios yes(1)/no(0), les deux à tenir.
        self::assertStringContainsString("'1' === r.value", $js);
        self::assertStringContainsString("'0' === r.value", $js);
    }

    public function testLeModeCodePasseParLeBoutonProxy(): void
    {
        // runCommand('preset-mautic:code-edit') PLANTE (« sender.set is not a
        // function ») : la commande du preset exige un bouton comme sender.
        $js = (string) file_get_contents(self::OPTIONS);

        self::assertStringContainsString('sendly-code-proxy', $js);
        self::assertStringContainsString('preset-mautic:code-edit', $js);
        self::assertStringContainsString("set('active', 1)", $js);
        self::assertStringNotContainsString("runCommand('preset-mautic:code-edit')", $js, 'appel direct = crash sender.set (constaté)');

        $theme = (string) file_get_contents(self::THEME);
        self::assertStringContainsString('.gjs-pn-btn.sendly-cache { display: none; }', $theme);
    }

    public function testLesContoursRestentDebrayablesEtLeMarqueurAvance(): void
    {
        $js = (string) file_get_contents(self::OPTIONS);
        self::assertStringContainsString("runCommand('sw-visibility')", $js);
        self::assertStringContainsString("stopCommand('sw-visibility')", $js);

        self::assertStringContainsString("--sendly-builder-theme: 'p3b'", (string) file_get_contents(self::THEME));
    }
}
