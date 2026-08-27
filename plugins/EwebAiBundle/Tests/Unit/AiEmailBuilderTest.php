<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * L'e-mail de bout en bout — lot 3 de l'assistant exécutant (audit 27/08).
 * Contrat verrouillé au niveau des SOURCES, comme AiPageBuilderTest.
 */
final class AiEmailBuilderTest extends TestCase
{
    private function source(string $file): string
    {
        $path = __DIR__.'/../../Assets/js/'.$file;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testLEtageEditeurNeVitQuEnEmailHtml(): void
    {
        $js = $this->source('ai-email-builder.js');

        // Le filtre de contexte du hook plugins nous gate hors MJML : aucune
        // discipline MJML à recoder ici, et surtout aucune insertion brute
        // dans un canvas MJML.
        self::assertStringContainsString("context: ['email-html']", $js);
        self::assertStringContainsString("name: 'sendly-ai-email'", $js);
        self::assertStringNotContainsString('mjmlToHtml', $js);
        self::assertStringNotContainsString("'email-mjml'", $js);
    }

    public function testLeBriefEstHorodateEtPerime(): void
    {
        $js = $this->source('ai-email-builder.js');

        self::assertStringContainsString('sessionStorage.getItem(CLE)', $js);
        self::assertStringContainsString('Date.now() - brief.ts > TTL', $js);
        self::assertStringContainsString('sessionStorage.removeItem(CLE)', $js);
    }

    public function testLEtageFormulaireFaitLesGestesNatifs(): void
    {
        $js = $this->source('ai-email-builder.js');

        // Le type SEGMENT par le geste natif (ferme aussi la modale), le
        // thème blank de préférence, l'ouverture par launchBuilder.
        self::assertStringContainsString("selectEmailType('list')", $js);
        self::assertStringContainsString('option[value="blank"]', $js);
        self::assertStringContainsString("launchBuilder('emailform')", $js);
    }

    public function testLeSegmentDestinataireEstUneCorrespondanceJamaisUneInvention(): void
    {
        $js = $this->source('ai-email-builder.js');

        // La référence prononcée par l'utilisateur se compare aux VRAIES
        // options de l'écran ; sans correspondance, le champ reste vide.
        self::assertStringContainsString('#emailform_lists', $js);
        self::assertStringContainsString('toLowerCase().indexOf(voulu)', $js);
    }

    public function testLaGenerationPasseParLEndpointGardeEtLitLaCleText(): void
    {
        $js = $this->source('ai-email-builder.js');

        // mQuery.ajax (jeton CSRF automatique), mode generate en HTML, et la
        // réponse se lit dans `text` — la clé réelle de generateAction.
        self::assertStringContainsString('mQuery.ajax', $js);
        self::assertStringContainsString("mode: 'generate'", $js);
        self::assertStringContainsString("format: 'html'", $js);
        self::assertStringContainsString('rep.text', $js);
        self::assertStringNotContainsString('fetch(', $js);
    }
}
