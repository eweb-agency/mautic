<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P3a de la refonte de l'éditeur (chantier D) : panneaux Styles contextuels.
 * Comme BuilderShellTest, le contrat est gravé au niveau des SOURCES —
 * chaque assertion correspond à un piège constaté en direct le 10/08.
 */
final class BuilderStylesTest extends TestCase
{
    private const STYLES     = __DIR__.'/../../Assets/js/builder-styles.js';
    private const COMPOSANTS = __DIR__.'/../../Assets/js/builder-composants.js';
    private const THEME      = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/css/builder-sendly.css';
    private const CONFIG     = __DIR__.'/../../Config/config.php';
    private const CONTROLLER = __DIR__.'/../../Controller/BuilderFormsController.php';

    public function testLaMatriceValideeEstComplete(): void
    {
        // La matrice des panneaux validée par le proprio, panneau par panneau.
        $js = (string) file_get_contents(self::STYLES);

        foreach (['Paramètres du texte', 'Paramètres du bouton', "Paramètres de l'image",
            'Paramètres de la section', 'Paramètres de la page', 'Paramètres de la vidéo',
            'Paramètres de la carte', 'Paramètres du compte à rebours',
            'Paramètres du séparateur', 'Paramètres de la barre de navigation',
            'Paramètres du formulaire'] as $panneau) {
            self::assertStringContainsString($panneau, $js, "panneau manquant : $panneau");
        }
        // Le contrôle signature de la maquette et l'héritage des polices.
        self::assertStringContainsString('sendly-slider', $js);
        self::assertStringContainsString("extend: 'font-family'", $js, 'sans extend, la liste de polices est VIDE (constaté)');
        // addSector à chaud rend chaque secteur deux fois (constaté).
        self::assertStringContainsString('dedupeSectorDom', $js);
    }

    public function testLaBasculeEstEnPurCssEtFailOpen(): void
    {
        // La bascule contextuelle ne touche AUCUNE vue (les vues fantômes du
        // remove/add sont un piège constaté) et le masquage exige l'attribut :
        // script absent => panneau intact.
        $theme = (string) file_get_contents(self::THEME);

        self::assertStringContainsString('[data-sendly-kind] .gjs-sm-sector { display: none; }', $theme);
        self::assertStringNotContainsString('.gjs-mode-page .gjs-sm-sector { display: none; }', $theme, 'masquage sans garde-attribut = panneau Styles VIDE si le script ne tourne pas');
        // La VALEUR du marqueur avance a chaque phase : seule la phase la
        // plus recente l'epingle (ici BuilderOptionsTest).
        self::assertStringContainsString('--sendly-builder-theme:', $theme);

        foreach (['texte', 'bouton', 'image', 'section', 'page', 'video', 'carte', 'rebours', 'separateur', 'navbar', 'formulaire'] as $kind) {
            self::assertStringContainsString('[data-sendly-kind="'.$kind.'"] .gjs-sm-sector__s-'.$kind, $theme, "règle de bascule manquante : $kind");
        }
    }

    public function testLeCadreSeCentreParFormatSansTranslate(): void
    {
        // Demande proprio 11/08 : cadre centré à chaque format. Par LEFT en
        // inline et EN RAFALE (le re-rendu écrase une application unique) —
        // jamais par translateX, qui désynchronise le calque d'outils.
        $js = (string) file_get_contents(self::STYLES);

        self::assertStringContainsString('centrerCadre', $js);
        self::assertStringContainsString('[100, 400, 800]', $js);
        self::assertStringContainsString('.style.left', $js);
        self::assertStringNotContainsString('translateX', $js);
    }

    public function testLesTraitsSontFrancises(): void
    {
        $js = (string) file_get_contents(self::STYLES);

        foreach (['Lien', 'Texte alternatif (SEO)', 'Ouvrir dans', 'Identifiant de la vidéo',
            'Lecture auto', 'Adresse', 'Cette fenêtre', 'Nouvel onglet'] as $libelle) {
            self::assertStringContainsString($libelle, $js, "trait non francisé : $libelle");
        }
    }

    public function testLaTuileFormulairePorteSonMarqueurEtSonEndpoint(): void
    {
        // Le sélecteur de formulaires : marqueur sur la tuile (P2), détection
        // + trait + jeton {form=N} (P3a), endpoint gardé comme l'écran natif.
        self::assertStringContainsString('data-sendly="form"', (string) file_get_contents(self::COMPOSANTS));

        $js = (string) file_get_contents(self::STYLES);
        self::assertStringContainsString('/sendly/builder-forms', $js);
        self::assertStringContainsString("'{form=' + n + '}'", $js);

        $config = (string) file_get_contents(self::CONFIG);
        self::assertStringContainsString("'path'       => '/sendly/builder-forms'", $config);

        $controller = (string) file_get_contents(self::CONTROLLER);
        self::assertStringContainsString("isGranted('form:forms:viewown')", $controller);
        self::assertStringContainsString("isGranted('form:forms:viewother')", $controller, 'la visibilité des formulaires des autres doit suivre la permission native');
        self::assertStringContainsString("'XMLHttpRequest' !== \$request->headers->get('X-Requested-With')", $controller);
    }

    public function testLesCurseursEcriventParUpValueEtGardentLUnite(): void
    {
        // Défaut proprio 11/08 : taille et espacement SANS EFFET. Deux
        // voleurs d'unité prouvés en pilotant les deux voies en session :
        // le champ `units` de la définition déclenche le découpage
        // valeur/unité du modèle de base (qui ne recolle jamais), et la
        // voie change()->updateStyle stocke « 63px » comme « 63 » ->
        // CSS invalide. Écriture par upValue du MODÈLE, unité portée par
        // un champ opaque pour GrapesJS.
        $js = (string) file_get_contents(self::STYLES);

        self::assertStringContainsString('sendlyUnit:', $js);
        self::assertStringContainsString('m.upValue(String(v) + unit', $js);
        self::assertStringContainsString("'sendly-slider' === p.get('type')", $js);
        // Plus AUCUNE trace des deux voies fautives dans le code.
        self::assertStringNotContainsString('arg.change', $js);
        self::assertStringNotContainsString('units: units', $js);
    }

    public function testLesCompositesHeritentDuNatifSinonLignesVides(): void
    {
        // Défaut proprio 11/08 (capture) : Marge interne/externe, Rayon,
        // Bordure, Ombre portée = étiquettes SANS AUCUN champ. Un composite
        // ou stack déclaré nu n'a AUCUN sous-champ ; hériter du natif via
        // `extend` construit tout (sonde live : 4/4 champs 124x27 rendus).
        $js = (string) file_get_contents(self::STYLES);

        foreach (["{ extend: 'padding', name: 'Marge interne' }",
            "{ extend: 'margin', name: 'Marge externe' }",
            "{ extend: 'border-radius', name: 'Rayon de bordure' }",
            "{ extend: 'border', name: 'Bordure' }",
            "{ extend: 'box-shadow', name: 'Ombre portée' }",
            "{ extend: 'background', name: 'Image / dégradé de fond' }"] as $def) {
            self::assertStringContainsString($def, $js);
        }
        self::assertStringNotContainsString("type: 'composite'", $js, 'composite nu = ligne vide');
        self::assertStringNotContainsString("type: 'stack'", $js, 'stack nu = ligne vide');
    }

    public function testLesSousChampsHeritesSontFrancises(): void
    {
        // Demande proprio 11/08 : les sous-champs hérités du natif arrivent
        // en anglais (Top, Right, Blur…). Re-libeller les MODÈLES ne re-rend
        // pas les vues (constaté) : traduction au niveau du DOM — le texte
        // vit dans `.gjs-sm-icon` — passe initiale + MutationObserver pour
        // les couches de pile rendues à la volée (ombres, fonds).
        $js = (string) file_get_contents(self::STYLES);

        self::assertStringContainsString('function franciserSousLibelles()', $js);
        self::assertStringContainsString('franciserSousLibelles();', $js);
        self::assertStringContainsString("'.gjs-sm-label .gjs-sm-icon'", $js);
        self::assertStringContainsString('MutationObserver', $js);
        foreach (["'Top': 'Haut'", "'Right': 'Droite'", "'Bottom': 'Bas'", "'Left': 'Gauche'",
            "'Blur': 'Flou'", "'Spread': 'Étendue'", "'X position': 'Position X'"] as $paire) {
            self::assertStringContainsString($paire, $js);
        }
    }

    public function testLesReglesEditeesPerdentLeursDoublonsPrefixes(): void
    {
        // Proprio 12/08 : « Rayon de bordure ne fonctionne pas » — la
        // valeur s'écrivait bien, mais le -webkit-border-radius du thème
        // importé, placé après dans la règle, l'écrasait en cascade (alias
        // navigateur). Toute règle ÉDITÉE perd ses doublons préfixés des
        // propriétés standards présentes ; les règles jamais touchées
        // restent telles qu'importées.
        $js = (string) file_get_contents(self::STYLES);

        self::assertStringContainsString('function purgerPrefixes(regle)', $js);
        self::assertStringContainsString("editor.Css.getAll().on('change:style', purgerPrefixes)", $js);
        foreach (["'-webkit-'", "'-moz-'", "'-o-'", "'-ms-'"] as $prefixe) {
            self::assertStringContainsString($prefixe, $js);
        }
    }
}
