<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Marque des pages d'erreur (relevé 02/11) : toute page d'erreur des
 * espaces, y compris la 404 publique vue par n'importe quel visiteur,
 * signait « Mautibot » et proposait « Signaler une anomalie » vers
 * mau.tc, le lien court de Mautic. Contrat verrouillé au niveau des
 * SOURCES, pour qu'une fusion de l'amont ne les réintroduise pas en
 * silence.
 */
final class ErrorPagesBrandingTest extends TestCase
{
    private const VUES = __DIR__.'/../../../../app/bundles/CoreBundle/Resources/views';

    /** Production (Error) et mode débogage (Exception). */
    private const GABARITS = ['Error/base.html.twig', 'Exception/base.html.twig'];

    public function testLesPagesDErreurAffichentToujoursLeCodeDErreur(): void
    {
        foreach (self::GABARITS as $gabarit) {
            // Garde-fou : un fichier absent rendrait les autres tests vides de sens.
            self::assertStringContainsString('{{ status_code }}', $this->lire($gabarit), $gabarit);
        }
    }

    public function testLesPagesDErreurNeSignentPasMautibot(): void
    {
        foreach (self::GABARITS as $gabarit) {
            self::assertStringNotContainsString('>Mautibot<', $this->lire($gabarit), $gabarit);
        }
    }

    public function testLesPagesDErreurNeRenvoientPasVersMautic(): void
    {
        foreach (self::GABARITS as $gabarit) {
            $twig = $this->lire($gabarit);

            self::assertStringNotContainsString('mau.tc', $twig, $gabarit);
            self::assertStringNotContainsString('mautic.core.report_issue', $twig, $gabarit);
        }
    }

    private function lire(string $gabarit): string
    {
        $chemin = self::VUES.'/'.$gabarit;
        self::assertFileExists($chemin);

        return (string) file_get_contents($chemin);
    }
}
