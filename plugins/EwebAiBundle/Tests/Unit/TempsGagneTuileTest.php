<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * La tuile « Temps gagné avec Sendly Copilot » du tableau de bord, direction
 * « Vague » (retenue par le propriétaire le 25/11/2026).
 *
 * Test de RENDU : le gabarit est rendu par Twig, avec les libellés français
 * du plugin, dans ses trois états (compteur plein, compteur vide, Copilot
 * inactif). Ce qu'il verrouille :
 *  - le chiffre en grand est celui du MOIS, et porte le repère que la
 *    coquille du Copilot met à jour à l'instant d'un gain ;
 *  - une durée ne s'écrit jamais « 1 h 60 » ;
 *  - huit semaines, la semaine en cours seule en vedette avec sa valeur ;
 *  - un état vide dit une phrase et propose un geste, jamais un chiffre ;
 *  - le titre natif du widget reste dans la page : c'est la poignée qui
 *    déplace la tuile.
 */
final class TempsGagneTuileTest extends TestCase
{
    private const GABARIT = 'SubscribedEvents/Dashboard/temps_gagne.html.twig';

    /**
     * @param array<string, mixed> $donnees
     */
    private function rendre(array $donnees): string
    {
        $libelles = parse_ini_file(__DIR__.'/../../Translations/fr/messages.ini');
        self::assertIsArray($libelles);

        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../Resources/views'), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('getAssetUrl', static fn (string $chemin): string => '/'.$chemin));
        $twig->addFilter(new TwigFilter('trans', static fn (string $cle): string => (string) ($libelles[$cle] ?? $cle)));

        return $twig->render(self::GABARIT, $donnees);
    }

    private static function texte(string $html): string
    {
        $sansStyle = (string) preg_replace('#<style>.*?</style>#s', '', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($sansStyle), ENT_QUOTES)));
    }

    public function testLeChiffreDuMoisEstEnGrandEtLeTotalDessous(): void
    {
        $html = $this->rendre([
            'actif'        => true,
            'secondsMonth' => 30300,   // 8 h 25
            'secondsTotal' => 51600,   // 14 h 20
            'semaines'     => [2100, 3000, 0, 5700, 7200, 3600, 8400, 11100],
        ]);

        self::assertStringContainsString('<p class="sendly-tg-grand" data-sendly-temps-mois>8<small>h</small> 25</p>', $html);
        self::assertStringContainsString('<p>gagnées ce mois-ci</p>', $html);
        self::assertStringContainsString('<div class="sendly-tg-total">Depuis le début<b>14&nbsp;h&nbsp;20</b></div>', $html);
        // Huit semaines ; une semaine sans gain est une barre basse, pas un trou.
        self::assertSame(8, preg_match_all('/class="sendly-tg-barre[ "]/', $html));
        self::assertSame(1, substr_count($html, 'sendly-tg-barre sendly-tg-nulle'));
        // La semaine en cours, seule en vedette, avec sa valeur (3 h 05).
        self::assertSame(1, substr_count($html, 'sendly-tg-barre sendly-tg-cours'));
        self::assertStringContainsString('<div class="sendly-tg-barre sendly-tg-cours" style="height:78%"><span>3&nbsp;h&nbsp;05</span></div>', $html);
        self::assertStringContainsString('<span>8 dernières semaines</span><span>estimation prudente</span>', $html);
        self::assertStringNotContainsString('sendly-tg-vide', $html);
    }

    public function testUnPremierMoisNAffichePasDeuxFoisLeMemeChiffre(): void
    {
        $html = $this->rendre([
            'actif'        => true,
            'secondsMonth' => 1320,
            'secondsTotal' => 1320,
            'semaines'     => [0, 0, 0, 0, 0, 0, 0, 1320],
        ]);

        self::assertStringContainsString('data-sendly-temps-mois>22<small>min</small></p>', $html);
        self::assertStringContainsString('class="sendly-tg-bas sendly-tg-seul"', $html);
        self::assertStringNotContainsString('sendly-tg-total', self::corps($html), 'le total ne répète pas le mois');
        self::assertStringContainsString('<span>22&nbsp;min</span>', $html);
    }

    public function testUneDureeNeSEcritJamaisAvecSoixanteMinutes(): void
    {
        // 3 599 s : arrondi à la minute, c'est une heure ronde, pas « 59 min »
        // d'un côté et « 0 h 60 » de l'autre.
        $html = $this->rendre(['actif' => true, 'secondsMonth' => 3599, 'secondsTotal' => 7199, 'semaines' => [0, 0, 0, 0, 0, 0, 3600, 3599]]);

        self::assertStringContainsString('data-sendly-temps-mois>1<small>h</small></p>', $html);
        self::assertStringContainsString('<p>gagnée ce mois-ci</p>', $html, 'une heure : le participe s’accorde');
        self::assertStringContainsString('<b>2&nbsp;h</b>', $html);
        self::assertStringNotContainsString('60', self::texte($html));
    }

    public function testLeCompteurVideDitUnePhraseEtProposeUnGeste(): void
    {
        $html = $this->rendre(['actif' => true, 'secondsMonth' => 0, 'secondsTotal' => 30, 'semaines' => array_fill(0, 8, 0)]);

        $texte = self::texte($html);
        self::assertStringContainsString('Temps gagné avec Sendly Copilot', $texte);
        self::assertStringContainsString('Le compteur démarrera à la première action exécutée par Sendly Copilot.', $texte);
        self::assertStringContainsString('Ouvrir Sendly Copilot', $texte);
        self::assertStringContainsString("window.SendlyAssistant.open('help')", html_entity_decode($html, ENT_QUOTES));
        self::assertStringNotContainsString('data-sendly-temps-mois', $html, 'aucun chiffre tant que rien n’a été gagné');
        self::assertStringNotContainsString('sendly-tg-barre', self::corps($html));
    }

    public function testCopilotInactifLeDitSansProposerDeLOuvrir(): void
    {
        $html = $this->rendre(['actif' => false, 'secondsMonth' => 0, 'secondsTotal' => 0, 'semaines' => array_fill(0, 8, 0)]);

        self::assertStringContainsString('Sendly Copilot n\'est pas actif sur votre espace.', self::texte($html));
        self::assertStringNotContainsString('sendly-tg-essai', self::corps($html));
        self::assertStringNotContainsString('data-sendly-temps-mois', $html);
    }

    public function testLaTuilePrendLaCarteSansPerdreLaPoigneeNiLeMenuDuWidget(): void
    {
        $html = $this->rendre(['actif' => true, 'secondsMonth' => 1320, 'secondsTotal' => 1320, 'semaines' => [0, 0, 0, 0, 0, 0, 0, 1320]]);

        // Le titre natif (h4.ui-sortable-handle) déplace le widget : il reste
        // dans la page, invisible, jamais en display:none.
        self::assertStringContainsString('.tile:has(.sendly-tg) > .card-header h4 { flex: 1; height: 36px;', $html);
        self::assertDoesNotMatchRegularExpression('/card-header[^{]*\{[^}]*display:\s*none/', $html);
        self::assertStringContainsString('.tile:has(.sendly-tg) > .card-header .dropdown-toggle i { color: #fff; }', $html);
        // La matière de la carte du Copilot : la vague, son grain, la police embarquée.
        self::assertStringContainsString('/plugins/EwebAiBundle/Assets/img/copilot-vague.jpg', $html);
        self::assertStringContainsString('/plugins/EwebAiBundle/Assets/img/copilot-grain.jpg', $html);
        self::assertStringContainsString('/plugins/EwebAiBundle/Assets/fonts/Helvena_Light.woff2', $html);
        // Elle mesure sa propre place : quart, moitié, pleine largeur, 215 px de haut.
        self::assertStringContainsString('container-type: size', $html);
        self::assertStringContainsString('@container (max-width: 430px)', $html);
        self::assertStringContainsString('@container (max-height: 260px)', $html);
        // La coquille met le chiffre à jour à l'instant d'un gain.
        $coquille = (string) file_get_contents(__DIR__.'/../../Assets/js/ai-assistant.js');
        self::assertStringContainsString("document.querySelector('[data-sendly-temps-mois]')", $coquille);
        self::assertStringContainsString("tuile.classList.add('sendly-tg-neuf');", $coquille);
    }

    /** Le balisage de la tuile, sans sa feuille de style. */
    private static function corps(string $html): string
    {
        return (string) preg_replace('#<style>.*?</style>#s', '', $html);
    }
}
