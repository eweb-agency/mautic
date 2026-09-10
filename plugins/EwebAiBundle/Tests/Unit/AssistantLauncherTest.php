<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Le contrat de l'ASSISTANT UNIQUE (directive proprio 07/08) : UNE coquille
 * (ai-assistant.js) au design de référence, partout — et des CONTEXTES qui ne
 * fournissent que le contenu (titre, accueil, raccourcis, action d'envoi).
 * Les surfaces sont du JavaScript agrégé sans harnais de DOM : on verrouille
 * le contrat au niveau des SOURCES, comme PortalMenuTest.
 */
final class AssistantLauncherTest extends TestCase
{
    private function source(string $file): string
    {
        $path = __DIR__.'/../../Assets/js/'.$file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testLaCoquilleEstUniqueEtPorteLeDesignDeReference(): void
    {
        $js = $this->source('ai-assistant.js');

        // Le panneau unique et ses éléments de référence (capture Webmecanik).
        foreach (['sendly-assist-panel', 'sendly-assist-title', 'sendly-assist-clear',
            'sendly-assist-ex', 'assistant.private', 'assistant.shortcuts', 'sendly-assist-undo'] as $piece) {
            self::assertStringContainsString($piece, $js);
        }
        // Le contenu vient du contexte actif, jamais de la coquille.
        foreach (['ctx.title()', 'ctx.shortcuts()', 'ctx.placeholder()', 'openCtx.welcome()', 'openCtx.thinking()'] as $dyn) {
            self::assertStringContainsString($dyn, $js);
        }
        // Le contexte le plus prioritaire disponible gagne.
        self::assertStringContainsString('priority', $js);
        // La façade que les contextes utilisent après navigation.
        self::assertStringContainsString('window.SendlyAssistant =', $js);
    }

    public function testLAideGeneraleEstLeContexteParDefaut(): void
    {
        $js = $this->source('ai-assistant.js');

        self::assertStringContainsString("id: 'help'", $js);
        self::assertStringContainsString('priority: 0', $js);
        // Plus AUCUN panneau d'aide séparé : l'ancien design est mort.
        self::assertStringNotContainsString('sendly-assist-chips', $js, 'l ancien panneau d aide (design divergent) doit disparaitre');
        self::assertStringNotContainsString('buildPanel', $js);
    }

    public function testLeSegmentEstUnContexteSansPanneauPropre(): void
    {
        $js = $this->source('ai-segment.js');

        self::assertStringContainsString("id: 'segment'", $js);
        self::assertStringContainsString('priority: 10', $js);
        foreach (['title:', 'welcome:', 'placeholder:', 'thinking:', 'shortcuts:', 'onSend:', 'onUndo:'] as $facet) {
            self::assertStringContainsString($facet, $js);
        }
        // Tout le DOM du panneau appartient à la coquille, plus à ce fichier.
        self::assertStringNotContainsString('sendly-seg-panel', $js, 'le segment ne doit plus posseder de panneau : la coquille est unique');
        self::assertStringNotContainsString('ensureStyles', $js);
        self::assertStringNotContainsString('sendly-seg-btn', $js);
        // La machinerie native reste intacte, elle.
        foreach (['Mautic.addLeadListFilter', 'data-sendly-turn', 'a.remove-selected', 'SendlyAssistant.reset'] as $keep) {
            self::assertStringContainsString($keep, $js);
        }
    }

    public function testLAccompagnementSuitLEcran(): void
    {
        // Exigence produit 12/08 (« tout le but de cet assistant ») : le
        // titre, l'accueil et les raccourcis de l'aide se calquent sur la
        // section courante, et la section part AU SERVEUR pour des
        // réponses contextualisées.
        $js = $this->source('ai-assistant.js');

        self::assertStringContainsString('var SECTIONS_AIDE = [', $js);
        self::assertStringContainsString('function sectionCourante()', $js);
        self::assertStringContainsString("'Assistant ' + s.nom", $js);
        self::assertStringContainsString('Vous êtes dans « ', $js);
        self::assertStringContainsString('return s.raccourcis;', $js);
        self::assertStringContainsString("section: (sectionCourante() || {}).nom || ''", $js);
        // Les 13 sections cartographiées, dont les SMS.
        foreach (['Contacts', 'Segments', 'Campagnes', 'E-mails', 'SMS', 'Formulaires', 'Rapports'] as $nom) {
            self::assertStringContainsString("'".$nom."'", $js);
        }
    }

    public function testLeServeurContextualiseParSection(): void
    {
        $service = (string) file_get_contents(__DIR__.'/../../Service/AiCopilotService.php');
        self::assertStringContainsString('string $section = \'\'', $service);
        self::assertStringContainsString('the user is currently in the « ', $service);

        $controller = (string) file_get_contents(__DIR__.'/../../Controller/AiController.php');
        self::assertStringContainsString("'section'  => mb_substr", $controller);
    }

    /**
     * Recette import CSV (09/09) : les selects de Mautic sont habillés par
     * Chosen — le <select> natif est masqué, `:visible` ne le voit pas, et
     * l'écran de correspondance n'exposait AUCUN champ à l'assistant (qui
     * « associait » dans le vide). Un select compte si son habillage l'est.
     */
    public function testLesSelectsHabillesParChosenComptentCommeVisibles(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../Assets/js/ai-assistant.js');

        self::assertStringContainsString('function champVisible(el)', $js);
        self::assertStringContainsString("el.id + '_chosen'", $js);
        self::assertStringContainsString(".next('.chosen-container').is(':visible')", $js);
        self::assertSame(2, substr_count($js, '.filter(function () { return champVisible(this); })'), 'le formulaire de travail ET le relevé d écran passent par le même filtre');
        self::assertStringNotContainsString(".filter(':visible')", $js);
    }

    /**
     * Recette 10/09 : la liste d'options d'un select partait plafonnée à 40
     * — « Nom de la société » (companyname) tombait hors liste sur l'écran
     * d'import et le modèle remplissait une valeur voisine. Plafond à 120,
     * et une valeur inconnue est résolue par LIBELLÉ sans casse ni accents ;
     * sans correspondance, rien n'est écrit (pas de « champ rempli » vide).
     */
    public function testLesOptionsDesSelectsVontJusquA120EtSeResolventParLibelle(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../../Assets/js/ai-assistant.js');

        self::assertStringContainsString('if (opts.length >= 120) { return false; }', $js);
        self::assertStringNotContainsString('opts.length >= 40', $js);
        self::assertStringContainsString('function resoudreValeurSelect(sel, value)', $js);
        self::assertStringContainsString("normalize('NFD')", $js);
        self::assertStringContainsString('if (null === value) { return false; }', $js);
    }
}
