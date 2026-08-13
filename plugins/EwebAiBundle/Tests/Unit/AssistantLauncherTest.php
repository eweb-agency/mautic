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
}
