<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Le pont instance → portail — les règles qui le portent :
 *  - le paramètre `saas_portal_url` existe avec un défaut non vide (sinon le
 *    menu disparaît silencieusement de toutes les instances) ;
 *  - la barre haute monte bien le partial (le retirer d'un refactor de
 *    navbar ferait disparaître la couture sans autre signal) ;
 *  - le partial se referme entièrement quand l'URL est vide, et ses liens
 *    sont SANS préfixe de langue (le portail next-intl choisit la locale —
 *    un /fr/ codé en dur enverrait les anglophones au mauvais endroit).
 */
final class PortalMenuTest extends TestCase
{
    private const CONFIG  = __DIR__.'/../../Config/config.php';
    private const NAVBAR  = __DIR__.'/../../../../app/bundles/CoreBundle/Resources/views/Default/navbar.html.twig';
    private const PARTIAL = __DIR__.'/../../../../app/bundles/CoreBundle/Resources/views/Menu/portal.html.twig';

    public function testLeParametreExisteAvecUnDefautNonVide(): void
    {
        $config = require self::CONFIG;

        self::assertArrayHasKey('parameters', $config);
        self::assertArrayHasKey('saas_portal_url', $config['parameters']);
        self::assertNotSame('', trim((string) $config['parameters']['saas_portal_url']));
    }

    public function testLaBarreHauteMonteLePartial(): void
    {
        $navbar = (string) file_get_contents(self::NAVBAR);

        self::assertStringContainsString('@MauticCore/Menu/portal.html.twig', $navbar);
    }

    public function testLePartialSeRefermeSansUrlEtNImposePasLaLocale(): void
    {
        $partial = (string) file_get_contents(self::PARTIAL);

        // Garde : URL vide = aucun vestige à l'écran.
        self::assertStringContainsString("configGetParameter('saas_portal_url')", $partial);
        self::assertStringContainsString('{% if portalUrl %}', $partial);

        // Les liens laissent le portail choisir la locale.
        self::assertStringContainsString('{{ portalUrl }}/dashboard/organization', $partial);
        self::assertStringNotContainsString('/fr/dashboard', $partial);

        // Les libellés passent par la traduction, jamais en dur.
        self::assertStringContainsString("'eweb.saas.portal.back'|trans", $partial);
    }
}
