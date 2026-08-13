<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Assistant de page EN PLACE — chantier D, P7 (maquette validée par le
 * proprio le 12/08) : plus aucun panneau flottant, tout se passe à
 * l'endroit du dépôt. Contrat verrouillé au niveau des SOURCES.
 */
final class AiPageBuilderTest extends TestCase
{
    private function source(string $file): string
    {
        $path = __DIR__.'/../../Assets/js/'.$file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testPlusAucunPanneauFlottant(): void
    {
        $js = $this->source('ai-page-builder.js');

        // Le contexte du panneau conversationnel P5 a DISPARU : l'assistant
        // de page ne s'inscrit plus dans SendlyAssistantContexts.
        self::assertStringNotContainsString('SendlyAssistantContexts', $js);
        self::assertStringNotContainsString('SendlyAssistant.open', $js);
        self::assertStringContainsString("name: 'sendly-ai-page'", $js);
        self::assertStringContainsString("context: ['page']", $js);
    }

    public function testLInviteSOuvreEnPlaceAuDepotEtAuClic(): void
    {
        $js = $this->source('ai-page-builder.js');

        // Dépôt de la tuile : l'invite s'ouvre À L'ENDROIT du dépôt.
        self::assertStringContainsString("'block:drag:stop'", $js);
        self::assertStringContainsString("'sendly-ia' !== bloc.get('id')", $js);
        self::assertStringContainsString('data-sendly-invite', $js);
        // Filet du dépôt (recette proprio 13/08) : même si GrapesJS ne
        // remet aucun composant exploitable, l'invite s'ouvre en fin de
        // page — un dépôt ne doit JAMAIS ne rien faire.
        self::assertStringContainsString(".filter(Boolean)", $js);
        self::assertStringContainsString("else { ouvrirInvite(editor.getWrapper(), editor.getWrapper().components().length, ''); }", $js);
        // Clic sur la tuile : insertion après la sélection, sinon fin de page.
        self::assertStringContainsString('sel.index() + 1', $js);
        self::assertStringContainsString('editor.getWrapper().components().length', $js);
        // Champ + raccourcis, Entrée déclenche.
        self::assertStringContainsString('Décris la section à générer…', $js);
        self::assertStringContainsString('RACCOURCIS', $js);
        self::assertStringContainsString("'Enter' === e.key", $js);
    }

    public function testLeSqueletteEtLaBarreContextuelle(): void
    {
        $js = $this->source('ai-page-builder.js');

        self::assertStringContainsString('sendly-ia-squelette', $js);
        self::assertStringContainsString('sendly-shimmer', $js);
        foreach (['↻ Régénérer', '✎ Ajuster', '✓ Garder'] as $bouton) {
            self::assertStringContainsString($bouton, $js);
        }
        // « Ajuster » rouvre la saisie PRÉ-REMPLIE de la dernière consigne.
        self::assertStringContainsString('ouvrirInvite(p, i, consigne)', $js);
        // La barre vit dans un composant dédié, purgeable.
        self::assertStringContainsString('data-sendly-barre', $js);
    }

    public function testLeBouclierCouvreSourisEtClavier(): void
    {
        $js = $this->source('ai-page-builder.js');

        // GrapesJS re-diffuse les clics du canvas et capte le clavier :
        // chaque élément d'interface est blindé (leçons P4/P6, clics réels).
        self::assertStringContainsString("['mousedown', 'mouseup', 'click', 'dblclick', 'keydown', 'keyup', 'keypress']", $js);
        self::assertStringContainsString('e.stopPropagation()', $js);
    }

    public function testAmeliorerEtTraduireVontSurLaMiniBarre(): void
    {
        $js = $this->source('ai-page-builder.js');

        // Décision P7-a : les retouches vivent sur la mini-barre du
        // composant texte sélectionné.
        self::assertStringContainsString("editor.Commands.add('sendly-ia-ameliorer'", $js);
        self::assertStringContainsString("editor.Commands.add('sendly-ia-traduire'", $js);
        self::assertStringContainsString("editor.on('component:selected', equiperMiniBarre)", $js);
        self::assertStringContainsString("'text' !== comp.get('type')", $js);
        // Traduction : menu de langues ancré au composant.
        self::assertStringContainsString('Traduire en…', $js);
        self::assertStringContainsString('LANGUES', $js);
        // Retouche annulable : mémo pris AVANT le remplacement.
        self::assertStringContainsString('var avant = sel.components()', $js);
    }

    public function testLaSurfacePageEtLeContratReseau(): void
    {
        $js = $this->source('ai-page-builder.js');

        // Le serveur sert un prompt LANDING dédié quand surface=page
        // (les textes « façon e-mail » étaient le défaut relevé en P5).
        self::assertStringContainsString("corps.surface = 'page'", $js);
        self::assertStringContainsString('window.SendlyAiConfig.endpoint', $js);
        self::assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
        self::assertStringContainsString('rep.text', $js);
        self::assertStringContainsString("L\\'assistant n\\'est pas activé sur cette instance.", $js);
    }

    public function testLesResidusDInterfaceNePartentJamaisEnBase(): void
    {
        $js = $this->source('ai-page-builder.js');

        // Invites et barres sont purgées du HTML exporté : une sauvegarde
        // pendant une génération ne fige JAMAIS notre interface dans la page.
        self::assertStringContainsString('editor.getHtml = function', $js);
        self::assertStringContainsString('data-sendly-(?:invite|barre)', $js);
    }

    public function testLIconographieIaEstCelleDeLaTuile(): void
    {
        // Question proprio 12/08 : l'interface P7 doit porter la MÊME
        // étincelle (SVG) que la tuile « Assistant IA », pas un caractère
        // approchant ; la traduction reste dans la famille Lucide.
        $js    = $this->source('ai-page-builder.js');
        $tuile = (string) file_get_contents(__DIR__.'/../../../EwebSaasBundle/Assets/js/builder-composants.js');

        self::assertStringContainsString('M9.1071 5.448', $js, 'le path de l étincelle de la tuile');
        self::assertStringContainsString('M9.1071 5.448', $tuile);
        self::assertStringContainsString('ICONE_IA', $js);
        self::assertStringContainsString('ICONE_LANGUES', $js);
        self::assertStringNotContainsString("label: '✦'", $js);
        self::assertStringNotContainsString('🌐', $js);
    }

    public function testLePromptServeurConnaitLaSurfacePage(): void
    {
        $service = (string) file_get_contents(__DIR__.'/../../Service/AiCopilotService.php');

        self::assertStringContainsString("'page' === (\$params['surface'] ?? '')", $service);
        self::assertStringContainsString('landing-page conversion copywriter', $service);
        self::assertStringContainsString('never lorem ipsum', $service);

        $controller = (string) file_get_contents(__DIR__.'/../../Controller/AiController.php');
        self::assertStringContainsString("'surface'", $controller);
    }

    public function testLaFicheDeCapacitesDeLAideConnaitLesSms(): void
    {
        // Capture proprio 12/08 : l'aide NIAIT l'envoi de SMS (« nous ne
        // proposons pas ») alors que Sendly le propose via un connecteur de
        // transport (Twilio…) — une fausse réponse dessert l'assistant.
        $service = (string) file_get_contents(__DIR__.'/../../Service/AiCopilotService.php');

        self::assertStringContainsString('SMS / text messages: fully supported', $service);
        self::assertStringContainsString('Twilio', $service);
        self::assertStringContainsString('never DENY one from the capability list', $service);
    }
}
