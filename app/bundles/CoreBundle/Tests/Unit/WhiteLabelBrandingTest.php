<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Garde de régression marque blanche (audit B-02).
 *
 * L'instance est le produit revendu par l'agence : la mention « Mautic »
 * ne doit apparaître nulle part côté client. Ces vérifications lisent les
 * gabarits réels et échouent si une fuite de marque revient — le pied de
 * page de l'app et de la connexion, et les pieds d'e-mails des thèmes
 * livrés (qui atteignent les destinataires finaux).
 */
final class WhiteLabelBrandingTest extends TestCase
{
    private function coreBundleDir(): string
    {
        // .../app/bundles/CoreBundle/Tests/Unit -> .../app/bundles/CoreBundle
        return \dirname(__DIR__, 2);
    }

    private function bundlesDir(): string
    {
        return \dirname($this->coreBundleDir());
    }

    private function repoRoot(): string
    {
        // .../app/bundles -> .../app -> repo root
        return \dirname($this->bundlesDir(), 2);
    }

    public function testAppFooterCarriesBrandNotMautic(): void
    {
        $footer = $this->coreBundleDir().'/Resources/views/Default/base.html.twig';
        self::assertFileExists($footer);
        $html = (string) file_get_contents($footer);

        // La clé i18n rendait « Copyright … Mautic » via le pack FR : elle
        // ne doit plus être appelée dans le pied de page.
        self::assertStringNotContainsString(
            "{% trans with {'%date%': 'now' | date('Y') } %}mautic.core.copyright{% endtrans %}",
            $html,
            'Le pied de page de l\'app rend encore la clé mautic.core.copyright (fuite « Mautic »).',
        );
        // La version exposée est un marqueur produit à masquer côté client.
        self::assertStringNotContainsString(
            'mauticAppVersion()',
            $html,
            'La version est encore exposée dans le pied de page de l\'app.',
        );
        self::assertStringContainsString('Sendly', $html);
    }

    public function testLoginFooterCarriesBrandNotMautic(): void
    {
        $login = $this->bundlesDir().'/UserBundle/Resources/views/Security/base.html.twig';
        self::assertFileExists($login);
        $html = (string) file_get_contents($login);

        self::assertStringNotContainsString(
            "'mautic.core.copyright'|trans",
            $html,
            'La page de connexion rend encore la clé mautic.core.copyright (fuite « Mautic »).',
        );
        self::assertStringContainsString('Sendly', $html);
    }

    public function testShippedEmailThemesHaveNoMauticFooter(): void
    {
        $themes = glob($this->repoRoot().'/themes/*/html/email.html.twig') ?: [];
        self::assertNotEmpty($themes, 'Aucun thème d\'e-mail trouvé — chemin inattendu.');

        foreach ($themes as $theme) {
            $html = (string) file_get_contents($theme);
            self::assertStringNotContainsString(
                'Mautic - All Rights Reserved',
                $html,
                sprintf('Le thème d\'e-mail %s fuit encore « Mautic » dans son pied.', basename(\dirname($theme, 2))),
            );
            self::assertStringNotContainsString(
                'Medford, MA',
                $html,
                sprintf('Le thème d\'e-mail %s contient encore l\'adresse par défaut de Mautic.', basename(\dirname($theme, 2))),
            );
        }
    }
}
