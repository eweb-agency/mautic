<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Le contrat du LANCEUR UNIQUE (directive proprio, motif Webmecanik) : un seul
 * bouton flottant ouvre l'assistant, et le panneau SUIT L'ONGLET — sur l'écran
 * d'édition d'un segment c'est « Assistant de segment » qui s'ouvre, ailleurs
 * l'aide générale. Les surfaces sont du JavaScript agrégé sans harnais de DOM :
 * on verrouille donc le contrat au niveau des SOURCES, comme PortalMenuTest.
 */
final class AssistantLauncherTest extends TestCase
{
    private function source(string $file): string
    {
        $path = __DIR__.'/../../Assets/js/'.$file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testLeSegmentNaPlusDeBoutonPropre(): void
    {
        $js = $this->source('ai-segment.js');

        self::assertStringNotContainsString('sendly-seg-btn', $js, 'le bouton dedie pres des filtres doit disparaitre : le lanceur flottant est le seul point d entree');
    }

    public function testLeSegmentSEnregistreCommeContexteDuLanceur(): void
    {
        $js = $this->source('ai-segment.js');

        self::assertStringContainsString('window.SendlyAssistantContexts', $js);
        // Les cinq facettes du contrat : dispo, titre d'onglet, etat, ouvrir, fermer.
        foreach (['available:', 'label:', 'isOpen:', 'open: openPanel', 'close: closePanel'] as $facet) {
            self::assertStringContainsString($facet, $js);
        }
        // Le titre qui suit l'onglet est celui du panneau segment, pas un libelle a part.
        self::assertStringContainsString('mautic.lead_list.ai.panel_title', $js);
    }

    public function testLeLanceurConsulteLeRegistreEtAdapteSonLibelle(): void
    {
        $js = $this->source('ai-assistant.js');

        self::assertStringContainsString('SendlyAssistantContexts', $js, 'le bouton flottant doit ouvrir le contexte de l ecran courant, pas toujours l aide generale');
        // Le libelle du lanceur (title/aria) suit lui aussi l'onglet.
        self::assertStringContainsString('refreshLabel', $js);
    }
}
