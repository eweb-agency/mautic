<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Marque de l'écran de connexion — B-02 (white-label) + demande proprio
 * 13/08 (capture) : l'écran d'erreur d'authentification montrait le « e »
 * seul et un message citant « Campaign Studio ». Contrat verrouillé au
 * niveau des SOURCES.
 */
final class LoginBrandingTest extends TestCase
{
    private const RACINE = __DIR__.'/../../../../app/bundles/UserBundle';

    public function testLEcranDeConnexionPorteLeLettrageSendly(): void
    {
        $twig = (string) file_get_contents(self::RACINE.'/Resources/views/Security/base.html.twig');

        // Le lettrage complet (logo--expanded, celui de la barre du haut),
        // pas le « e » seul (logo--minimized).
        self::assertStringContainsString('logo--expanded.svg', $twig);
        self::assertStringNotContainsString('logo--minimized.svg', $twig);
    }

    public function testLeLettrageEstDimensionneDansLeCadre(): void
    {
        $css = (string) file_get_contents(self::RACINE.'/Assets/css/user.css');

        // Le SVG 629x201 n'a NI width NI height : sans cette règle il
        // retombe sur le 300x150 par défaut des remplacés et déborde.
        self::assertStringContainsString('.mautic-logo > svg', $css);
        self::assertStringContainsString('width: 100%', $css);
    }

    public function testLeMessageSamlNeCiteAucuneMarqueEtrangere(): void
    {
        $ini = (string) file_get_contents(self::RACINE.'/Translations/en_US/flashes.ini');

        self::assertStringContainsString('contact your administrator.', $ini);
        self::assertStringNotContainsString('Campaign Studio', $ini);
    }
}
