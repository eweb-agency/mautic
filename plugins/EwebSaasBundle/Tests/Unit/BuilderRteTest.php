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
        self::assertStringContainsString('max-width: 460px', $js);
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

    public function testLeMarqueurDeThemeEstEnP4(): void
    {
        self::assertStringContainsString("--sendly-builder-theme: 'p4'", (string) file_get_contents(self::THEME));
    }
}
