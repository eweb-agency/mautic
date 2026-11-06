<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Rendu réel du gabarit, avec les textes réels des deux langues : c'est ce
 * que voit un destinataire, donc ce qui doit rester vrai et neutre.
 */
final class EmailPreferencesPageTest extends TestCase
{
    private const ADDRESS = 'camille@exemple.fr';

    private const URLS = [
        'frequency'   => '/email/preferences/hachage/frequency',
        'save'        => '/email/preferences/hachage/frequency',
        'unsubscribe' => '/email/preferences/hachage/unsubscribe',
        'resubscribe' => '/email/preferences/hachage/resubscribe',
    ];

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function ecrans(): iterable
    {
        foreach (['fr', 'en_US'] as $locale) {
            yield $locale.' accueil' => [$locale, 'home', []];
            yield $locale.' fréquence' => [$locale, 'frequency', ['rhythm' => 'weekly']];
            yield $locale.' confirmation semaine' => [$locale, 'saved', ['rhythm' => 'weekly']];
            yield $locale.' confirmation mois' => [$locale, 'saved', ['rhythm' => 'monthly']];
            yield $locale.' désabonné' => [$locale, 'unsubscribed', ['canResubscribe' => true]];
            yield $locale.' réabonné' => [$locale, 'resubscribed', []];
            yield $locale.' lien invalide' => [$locale, 'invalid', []];
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('ecrans')]
    public function testLaPageResteNeutreEtSansScript(string $locale, string $screen, array $parameters): void
    {
        $html = $this->render($locale, $screen, $parameters);

        self::assertStringNotContainsStringIgnoringCase('mautic', $html);
        self::assertStringNotContainsString('—', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('eweb.saas.preferences', $html, 'Texte non traduit');
        self::assertStringContainsString('noindex', $html);
        self::assertStringContainsString('content="no-referrer"', $html);
        // Le gabarit ne charge que ses propres polices : rien à qui transmettre l'URL.
        self::assertDoesNotMatchRegularExpression('#(src|href)="https?://#', $html);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('ecrans')]
    public function testLAdresseNEstJamaisDansUneUrl(string $locale, string $screen, array $parameters): void
    {
        $html = $this->render($locale, $screen, $parameters);

        preg_match_all('/(?:href|action)="([^"]*)"/', $html, $urls);

        foreach ($urls[1] as $url) {
            self::assertStringNotContainsString('@', $url);
        }
    }

    public function testSeDesabonnerEstSurLePremierEcranEnUnVraiBouton(): void
    {
        $html = $this->render('fr', 'home');

        self::assertStringContainsString('<form method="post" action="/email/preferences/hachage/unsubscribe">', $html);
        self::assertStringContainsString('<button class="button secondary" type="submit">Se désabonner</button>', $html);
        self::assertStringContainsString('href="/email/preferences/hachage/frequency">Gérer la fréquence</a>', $html);
        self::assertStringContainsString(self::ADDRESS, $html);
    }

    public function testLeCheminPourSeDesabonnerResteVisibleDepuisLaFrequence(): void
    {
        $html = $this->render('fr', 'frequency', ['rhythm' => 'weekly']);

        self::assertStringContainsString('me désabonner</button>', $html);
        self::assertStringContainsString('action="/email/preferences/hachage/unsubscribe"', $html);
    }

    public function testLeChoixEnregistreEstCoche(): void
    {
        $html = $this->render('fr', 'frequency', ['rhythm' => 'monthly']);

        self::assertStringContainsString('value="monthly" checked', $html);
        self::assertStringNotContainsString('value="weekly" checked', $html);
    }

    public function testLaPageNeProposeQueCeQueLesEnvoisAppliquent(): void
    {
        $html = $this->render('fr', 'frequency', ['rhythm' => 'weekly']);

        self::assertSame(3, substr_count($html, 'type="radio"'));
        self::assertStringContainsString('Faire une pause de 3 mois', $html);
    }

    public function testUnePauseEnCoursAfficheSaDateDeReprise(): void
    {
        $html = $this->render('fr', 'frequency', ['rhythm' => 'pause', 'resume' => '6 février 2027']);

        self::assertStringContainsString('value="pause" checked', $html);
        self::assertStringContainsString('En pause jusqu&#039;au 6 février 2027', $html);
        self::assertStringNotContainsString('Faire une pause de 3 mois', $html);
    }

    /**
     * Le plafond et le désabonnement ne s'appliquent qu'aux envois marketing :
     * le texte ne doit pas promettre plus.
     *
     * @return iterable<string, array{string, string, array<string, mixed>, string}>
     */
    public static function promesses(): iterable
    {
        yield 'fr semaine' => ['fr', 'saved', ['rhythm' => 'weekly'], 'nos e-mails marketing une fois par semaine au maximum'];
        yield 'fr mois' => ['fr', 'saved', ['rhythm' => 'monthly'], 'nos e-mails marketing une fois par mois au maximum'];
        yield 'fr pause' => ['fr', 'saved', ['rhythm' => 'pause', 'resume' => '6 février 2027'], 'pas nos e-mails marketing jusqu&#039;au 6 février 2027'];
        yield 'fr désabonné' => ['fr', 'unsubscribed', ['canResubscribe' => false], 'plus nos e-mails marketing à camille@exemple.fr'];
        yield 'en semaine' => ['en_US', 'saved', ['rhythm' => 'weekly'], 'our marketing emails once a week at most'];
        yield 'en mois' => ['en_US', 'saved', ['rhythm' => 'monthly'], 'our marketing emails once a month at most'];
        yield 'en pause' => ['en_US', 'saved', ['rhythm' => 'pause', 'resume' => 'February 6, 2027'], 'not get our marketing emails until February 6, 2027'];
        yield 'en désabonné' => ['en_US', 'unsubscribed', ['canResubscribe' => false], 'our marketing emails at camille@exemple.fr'];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('promesses')]
    public function testLesPromessesSontLimiteesAuMarketing(string $locale, string $screen, array $parameters, string $expected): void
    {
        self::assertStringContainsString($expected, $this->render($locale, $screen, $parameters));
    }

    public function testLeReabonnementNApparaitQueSiLeContactPeutLeFaire(): void
    {
        self::assertStringContainsString('Me réabonner', $this->render('fr', 'unsubscribed', ['canResubscribe' => true]));
        self::assertStringNotContainsString('Me réabonner', $this->render('fr', 'unsubscribed', ['canResubscribe' => false]));
    }

    public function testLeBleuSendlyEstCeluiDeTousLesClients(): void
    {
        self::assertStringContainsString('#004FFF', $this->render('fr', 'frequency', ['rhythm' => 'weekly']));
    }

    public function testLaMarqueEtLAdresseSontEchappees(): void
    {
        $html = $this->render('fr', 'home', ['brand' => '<b>Marque</b>', 'address' => 'a"><script>@exemple.fr']);

        self::assertStringNotContainsString('<b>Marque</b>', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testSansMarqueLeBlocDisparait(): void
    {
        self::assertStringNotContainsString('class="brand"', $this->render('fr', 'invalid', ['brand' => '']));
    }

    public function testLeLogoRemplaceLeNomQuandIlExiste(): void
    {
        $html = $this->render('fr', 'home', ['logo' => 'media/images/sendly/logo-abc.png']);

        self::assertStringContainsString('<img class="logo" src="/media/images/sendly/logo-abc.png" alt="Atelier Mistral">', $html);
        self::assertStringNotContainsString('class="brand"', $html);
    }

    public function testLesDeuxLanguesOntLesMemesCles(): void
    {
        $fr = array_filter(array_keys($this->messages('fr')), self::isPageKey(...));
        $en = array_filter(array_keys($this->messages('en_US')), self::isPageKey(...));

        self::assertNotEmpty($fr);
        self::assertSame(array_values($fr), array_values($en));
    }

    private static function isPageKey(string $key): bool
    {
        return str_starts_with($key, 'eweb.saas.preferences.');
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $locale, string $screen, array $parameters = []): string
    {
        $messages = $this->messages($locale);

        $twig = new Environment(new FilesystemLoader(__DIR__.'/../../Resources/views'), ['autoescape' => 'html', 'strict_variables' => true]);
        $twig->addFunction(new TwigFunction('getAssetUrl', static fn (string $path): string => '/'.$path));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $replace = []): string => strtr($messages[$key] ?? $key, $replace)));

        return $twig->render('EmailPreferences/page.html.twig', array_merge([
            'screen'         => $screen,
            'language'       => substr($locale, 0, 2),
            'brand'          => 'Atelier Mistral',
            'address'        => self::ADDRESS,
            'urls'           => self::URLS,
            'rhythm'         => 'weekly',
            'resume'         => null,
            'logo'           => null,
            'canResubscribe' => true,
        ], $parameters));
    }

    /**
     * @return array<string, string>
     */
    private function messages(string $locale): array
    {
        $file = __DIR__.'/../../Translations/'.$locale.'/messages.ini';
        self::assertFileExists($file);

        $messages = parse_ini_file($file);
        self::assertIsArray($messages);

        return $messages;
    }
}
