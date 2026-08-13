<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Builder sur téléphone — chantier D, P8 (maquette validée par le proprio
 * le 12/08, décisions P8-a « Enregistrer dans ⋯ » et P8-b « ajout par
 * touche » acceptées). Contrat verrouillé au niveau des SOURCES.
 */
final class BuilderMobileTest extends TestCase
{
    private const JS    = __DIR__.'/../../Assets/js/builder-mobile.js';
    private const THEME = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/css/builder-sendly.css';

    public function testLeModuleEstScopeAuModePage(): void
    {
        $js = (string) file_get_contents(self::JS);

        self::assertStringContainsString("name: 'sendly-builder-mobile'", $js);
        self::assertStringContainsString("context: ['page']", $js);
        // Bascule par matchMedia + classe de recette pour fenêtre large.
        self::assertStringContainsString("matchMedia('(max-width: 767px)')", $js);
        self::assertStringContainsString('sendly-mobile-force', $js);
    }

    public function testLesOngletsPassentDansLaBarreDuBas(): void
    {
        $js = (string) file_get_contents(self::JS);

        // Les 3 onglets de la maquette, câblés sur les boutons de vues
        // EXISTANTS (aucune logique de vue dupliquée).
        foreach (['Composants', 'Styles', 'Options'] as $onglet) {
            self::assertStringContainsString("'".$onglet."'", $js);
        }
        self::assertStringContainsString("'open-blocks'", $js);
        self::assertStringContainsString("'open-sm'", $js);
        self::assertStringContainsString("'sendly-options'", $js);
        self::assertStringContainsString("editor.Panels.getButton('views', o.cible)", $js);
        self::assertStringContainsString('sendly-mobile-nav', $js);
    }

    public function testLAjoutSeFaitParToucheEtEpargneLaTuileIa(): void
    {
        $js = (string) file_get_contents(self::JS);

        // Décision P8-b : taper une tuile insère après la sélection.
        self::assertStringContainsString('editor.BlockManager.getAll()', $js);
        self::assertStringContainsString('sel.index() + 1', $js);
        self::assertStringContainsString("parent.append(bloc.get('content'), { at: index })", $js);
        // La tuile Assistant IA garde son parcours P7 (invite en place).
        self::assertStringContainsString("'Assistant IA' === libelle", $js);
        self::assertStringContainsString('fermerFeuille(); return;', $js);
    }

    public function testLeTexteSEditeAuTapEnMobile(): void
    {
        $js = (string) file_get_contents(self::JS);

        // Retour proprio 13/08 : le double-clic est un geste invisible au
        // doigt. Re-taper un texte DÉJÀ sélectionné monte le RTE ; la
        // sélection issue du tap en cours est ignorée (fenêtre 350 ms),
        // et l'écouteur vit dans l'IFRAME (les re-diffusions GrapesJS
        // vont au document principal — leçon P4).
        self::assertStringContainsString('poserEditionTactile', $js);
        self::assertStringContainsString("'text' !== sel.get('type')", $js);
        self::assertStringContainsString('selectionRecente < 350', $js);
        self::assertStringContainsString("dispatchEvent(new MouseEvent('dblclick'", $js);
        self::assertStringContainsString('sendly-rte-active', $js);
        self::assertStringContainsString("editor.on('canvas:frame:load', poserEditionTactile)", $js);
        // En phase de CAPTURE : en bulle, GrapesJS avale le clic avant le
        // document de l'iframe (constaté live 13/08 — clicRecu: false).
        self::assertStringContainsString('}, true);', $js);
    }

    public function testLeMenuTroisPointsPorteLesActionsSecondaires(): void
    {
        $js = (string) file_get_contents(self::JS);

        // Décision P8-a : Enregistrer quitte la barre pour le menu ⋯ ;
        // Annuler + Terminer restent seuls visibles (CSS data-sendly-id).
        foreach (['Enregistrer', 'Aperçu', 'Annuler la dernière action', 'Rétablir', 'Effacer la page'] as $entree) {
            self::assertStringContainsString("'".$entree."'", $js);
        }
        self::assertStringContainsString("cliquerBouton('sendly-enregistrer')", $js);
        self::assertStringContainsString('editor.UndoManager.undo()', $js);
        self::assertStringContainsString("setAttribute('data-sendly-id'", $js);
        self::assertStringContainsString('sendly-menu-plus', $js);
    }

    public function testLaFeuilleDuBasEstDansLeTheme(): void
    {
        $theme = (string) file_get_contents(self::THEME);

        // Panneau → feuille du bas (poignée, coins arrondis, glissement),
        // barre de navigation fixe, pilule d'appareils masquée. Tout scopé
        // .gjs-mode-page.sendly-mobile : l'éditeur d'e-mails est intact.
        self::assertStringContainsString('.gjs-mode-page.sendly-mobile .gjs-pn-views-container', $theme);
        self::assertStringContainsString('transform: translateY(105%)', $theme);
        self::assertStringContainsString('.sendly-feuille-ouverte .gjs-pn-views-container { transform: translateY(0); }', $theme);
        self::assertStringContainsString('.gjs-mode-page.sendly-mobile .sendly-mobile-nav', $theme);
        self::assertStringContainsString('.gjs-mode-page.sendly-mobile .gjs-pn-devices-c { display: none; }', $theme);
        self::assertStringContainsString('sendly-poignee', $theme);
        // Aucune règle mobile hors scope mode page.
        foreach (explode("\n", $theme) as $ligne) {
            if (str_contains($ligne, '.sendly-mobile ') && str_contains($ligne, '{')) {
                self::assertStringContainsString('.gjs-mode-page.sendly-mobile', $ligne, 'règle mobile non scopée : '.$ligne);
            }
        }
    }
}
