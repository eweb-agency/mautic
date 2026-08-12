<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P4 de la refonte de l'éditeur (chantier D) : l'édition de texte INLINE.
 * Contrat gravé au niveau des SOURCES — chaque assertion correspond à un
 * piège CONSTATÉ en direct le 11/08 pendant la sculpture. En perdre un,
 * c'est revivre le bug correspondant en silence.
 */
final class BuilderRteTest extends TestCase
{
    private const RTE   = __DIR__.'/../../Assets/js/builder-rte.js';
    private const THEME = __DIR__.'/../../../GrapesJsBuilderBundle/Assets/css/builder-sendly.css';

    public function testLIntegrationPasseParSetCustomRteEtPreserveLArbre(): void
    {
        // La SEULE architecture sans course : GrapesJS ne connaît qu'un RTE
        // (un clic-extérieur pendant un montage en vol VIDAIT le contenu en
        // double-gestion — constaté) ; parseContent rend un ARBRE.
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString('editor.setCustomRte({', $js);
        self::assertStringContainsString('parseContent: true', $js);
        self::assertStringNotContainsString("components('')", $js, "components('') aplatit l'arbre (piège historique de la modale)");
        self::assertStringContainsString("context: ['page']", $js, "l'éditeur d'e-mails garde sa modale");
    }

    public function testLaConfigEstCloneeDansLeRealmDeLIframe(): void
    {
        // Une config du realm principal fait PLANTER create() dans l'iframe
        // — et l'échec VIDE l'élément édité (constaté).
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString('iwin.JSON.parse(JSON.stringify(', $js);
        self::assertStringContainsString('Mautic.MentionLinks', $js);
        // La mention (autocomplétion {) est ENTIÈREMENT même-realm : feed
        // SYNCHRONE renvoyant des objets clonés dans le realm de l'iframe,
        // renderer construit avec le document de l'iframe. Les fonctions
        // cross-realm du core restaient MUETTES au clavier (constaté proprio).
        self::assertStringNotContainsString('Mautic.getFeedItems', $js);
        self::assertStringNotContainsString('Mautic.customItemRenderer', $js);
        self::assertStringContainsString('idoc.createElement', $js);
        self::assertStringContainsString('minimumCharacters: 0', $js);
    }

    public function testLaBarreEstCompacteSansPerdreDeCapacite(): void
    {
        // Demande proprio 11/08 : essentiels visibles, le reste replié dans
        // le « ⋯ » natif (groupement au débordement + max-width).
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString('shouldNotGroupWhenFull = false', $js);
        // Borné AUSSI à la largeur visible de la frame : en mobile, un bloc
        // plus large faisait scroller l'iframe et emportait la barre
        // hors-champ (constaté proprio).
        self::assertStringContainsString('max-width: min(460px, calc(100vw - 16px))', $js);
        self::assertStringContainsString('iwin.scrollTo({ left: 0 })', $js);
        // Tout y est toujours : rien ne quitte la toolbar, elle se replie.
        foreach (['insertTable', 'fontColor', 'TokenPlugin', 'alignment'] as $item) {
            self::assertStringContainsString($item, $js, "capacité perdue : $item");
        }
    }

    public function testLesJetonsSontUnTableauPrechargeEnAsynchrone(): void
    {
        $js = (string) file_get_contents(self::RTE);

        // Le bouton Jetons fait .map sur dynamicToken : TABLEAU obligatoire
        // (« e.map is not a function » avec l'objet — constaté).
        self::assertStringContainsString('Mautic.builderTokensForCkEditor || []', $js);
        // Jamais l'appel bloquant du core (ajax async: false = gel d'UI).
        self::assertStringNotContainsString('getTokensForPlugIn', $js);
        self::assertStringNotContainsString('async: false', $js);
        // La toolbar passée au helper est SANS TokenPlugin (c'est ce mot-clé
        // qui déclenche l'appel bloquant) ; le bouton est réinjecté ensuite.
        self::assertStringContainsString('TOOLBAR_SANS_TOKEN', $js);
        self::assertStringContainsString('base.toolbar.items = TOOLBAR', $js);
    }

    public function testLaModaleDuPresetEstNeutraliseeDesDeuxCotes(): void
    {
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString("editor.off('rte:enable')", $js);
        self::assertStringContainsString("'cke-modal' === opts.attributes.class", $js);
    }

    public function testEchapAbandonneParLaSeuleVoieDeFermetureProuvee(): void
    {
        // disableEditing sur la vue et editor.select() laissent l'éditeur
        // monté (constaté) : Échap restaure l'origine puis SIMULE le
        // clic-extérieur natif, la voie que GrapesJS sait fermer.
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString('rte.__cke.setData(rte.__origine)', $js);
        self::assertStringContainsString("['mousedown', 'mouseup', 'click']", $js);
        self::assertStringContainsString('MouseEvent', $js);
    }

    public function testLeMontageEnVolNePerdJamaisDeContenu(): void
    {
        // Défaut proprio 11/08 : premier montage lent (bundle ~1-2 s) ; un
        // clic pendant le vol fermait la session sur un élément déjà
        // remplacé -> contenu VIDE, le composant « disparaissait ».
        $js = (string) file_get_contents(self::RTE);

        // L'origine est capturée en SYNCHRONE, avant tout await.
        self::assertStringContainsString('el.__sendlyOrigine = el.innerHTML', $js);
        // Jeton de génération PAR ÉLÉMENT : la fermeture invalide le vol.
        self::assertStringContainsString('el.__sendlyGen = (el.__sendlyGen || 0) + 1', $js);
        self::assertStringContainsString('gen !== el.__sendlyGen', $js);
        // Et le bundle se précharge au load : plus de fenêtre de 1-2 s.
        self::assertStringContainsString('chargerBundle().catch', $js);
    }

    public function testLEtatEstPorteParLElementJamaisPartage(): void
    {
        // 2e vague du défaut (reproduite + journalisée en direct 11/08) :
        // l'état vivait sur l'objet RTE PARTAGÉ, et GrapesJS active le texte
        // suivant AVANT de désactiver le précédent -> les données de A
        // partaient dans B (textes écrasés ou vidés). L'état est désormais
        // porté par CHAQUE ÉLÉMENT, et le chevauchement ferme la session
        // précédente d'office, modèle synchronisé par NOS soins (le disable
        // tardif de GrapesJS n'est pas suivi d'un getContent, constaté).
        $js = (string) file_get_contents(self::RTE);

        foreach (['__sendlyOrigine', '__sendlyDonnee', '__sendlyCke', '__sendlyGen', '__sendlyComp'] as $champ) {
            self::assertStringContainsString('el.'.$champ, $js);
        }
        self::assertStringContainsString('fermerSession(self.__actif)', $js);
        self::assertStringContainsString('comp.components(deballer(el, contenu))', $js);
    }

    public function testLeDomEstRestaureApresChaqueDemontage(): void
    {
        // LE « composant qui disparaît » élucidé : CKE laisse l'élément VIDE
        // après destroy, et si le contenu n'a pas changé GrapesJS ne re-rend
        // pas (modèle identique) -> texte intact au modèle, INVISIBLE à
        // l'écran. Toute destruction passe donc par la restauration du DOM.
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString('function detruireEtRestaurer(cke, el)', $js);
        self::assertStringContainsString('el.innerHTML = deballer(el, c)', $js);
        // Plus AUCUN destroy nu : tout démontage restaure.
        self::assertStringNotContainsString('cke.destroy().catch(function () {});', $js);
    }

    public function testUnClicDansLeChromeFermeDAbordLaSession(): void
    {
        // Recette proprio 11/08 : pendant l'édition, un clic sur la barre
        // haute laissait l'éditeur flottant, et « Terminer » pouvait
        // appliquer la page SANS la saisie en cours. Tout mousedown du
        // document principal (le canvas est dans l'iframe, jamais concerné)
        // ferme d'abord la session — seul l'éditeur COURANT agit, le
        // document survivant aux recyclages du builder.
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString('window.__sendlyEditeurCourant = editor', $js);
        self::assertStringContainsString("document.addEventListener('mousedown'", $js);
        self::assertStringContainsString('editor !== window.__sendlyEditeurCourant', $js);
        self::assertStringContainsString('fermerSession(el)', $js);
    }

    public function testLeDeballageEviteLImbricationDeParagraphes(): void
    {
        // CKE rend `<p>…</p>` même quand le composant EST un <p> : sans
        // déballage, chaque cycle d'édition imbriquait un <p> de plus.
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString('function deballer(el, html)', $js);
        self::assertStringContainsString("'P' === el.tagName", $js);
    }

    public function testLesClicsReelsDansCkeNeFermentPasLaSession(): void
    {
        // 3e vague (clics RÉELS du proprio, invisibles aux événements
        // synthétiques) : CKE REMPLACE l'élément d'origine — pour GrapesJS,
        // cliquer dans l'éditable ou sur la barre CKE était « dehors » et
        // fermait la session au premier clic. Le bouclier coupe la REMONTÉE
        // des événements souris depuis l'enveloppe CKE (phase cible
        // préservée : les boutons fonctionnent).
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString('function poserBouclier(cke)', $js);
        self::assertStringContainsString('cke.ui.element', $js);
        self::assertStringContainsString('e.stopPropagation()', $js);
        self::assertStringContainsString('poserBouclier(cke)', $js);
    }

    public function testLeFermeurDeChromeIgnoreLesRediffusionsDuCanvas(): void
    {
        // GrapesJS RE-DIFFUSE chaque clic du canvas sur le document
        // principal avec l'IFRAME pour cible (constaté aux sondes) : sans
        // la garde, cliquer DANS le texte édité fermait la session.
        $js = (string) file_get_contents(self::RTE);

        self::assertStringContainsString("'IFRAME' === e.target.tagName", $js);
        self::assertStringContainsString(".closest('.gjs-cv-canvas')", $js);
    }

    public function testLaMiniBarreComposantEstMasqueePendantLaSession(): void
    {
        // La mini-barre (déplacer/supprimer) flottait PILE sous la barre
        // CKE : un clic pour elle fermait la session, voire supprimait le
        // composant via la corbeille.
        $js    = (string) file_get_contents(self::RTE);
        $theme = (string) file_get_contents(self::THEME);

        self::assertStringContainsString("classList.add('sendly-rte-active')", $js);
        self::assertStringContainsString("classList.remove('sendly-rte-active')", $js);
        self::assertStringContainsString('body.sendly-rte-active .gjs-mode-page .gjs-toolbar', $theme);
    }

    public function testLeMarqueurDeThemeExiste(): void
    {
        // La VALEUR est épinglée par la phase la plus récente (BuilderModalesTest).
        self::assertStringContainsString('--sendly-builder-theme:', (string) file_get_contents(self::THEME));
    }
}
