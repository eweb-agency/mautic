<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit;

use GuzzleHttp\Client;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * L'IA est un droit du PLAN — décision produit 13/08 : plans payants
 * uniquement, teaser + upsell côté gratuit. Matrice d'entitlement pilotée
 * par SENDLY_AI_ENTITLED (poussée par le portail), RÉTRO-COMPATIBLE :
 * sans variable, rien ne change pour les tenants existants.
 */
final class AiEntitlementTest extends TestCase
{
    private array $sauvegarde = [];

    protected function setUp(): void
    {
        foreach (['SENDLY_ANTHROPIC_KEY', 'SENDLY_AI_ENTITLED'] as $cle) {
            $this->sauvegarde[$cle] = $_ENV[$cle] ?? null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sauvegarde as $cle => $valeur) {
            if (null === $valeur) {
                unset($_ENV[$cle], $_SERVER[$cle]);
                putenv($cle);
            } else {
                $_ENV[$cle] = $valeur;
            }
        }
    }

    private function service(): AiCopilotService
    {
        // Poste local sans vendor : l'autoload peut charger la classe d'un
        // AUTRE clone (worktree) — la CI, elle, exécute la vraie classe.
        if (!method_exists(AiCopilotService::class, 'isEntitled')) {
            self::markTestSkipped('classe chargée depuis un autre clone — la CI exécute ce test sur la vraie classe');
        }

        return new AiCopilotService(new Client(), new NullLogger());
    }

    public function testLaMatriceEstDansLaSource(): void
    {
        // Verrou de SOURCE (toujours exécuté, même sur poste local) : la
        // matrice exacte — '0' → pas de droit ; défaut '1' rétro-compatible ;
        // isEnabled exige clé ET droit.
        $service = (string) file_get_contents(__DIR__.'/../../Service/AiCopilotService.php');

        self::assertStringContainsString("'0' !== (\$this->env('SENDLY_AI_ENTITLED') ?? '1')", $service);
        self::assertStringContainsString('null !== $this->apiKey && $this->isEntitled()', $service);
        self::assertStringContainsString('return !$this->isEntitled();', $service);
    }

    public function testSansVariableLeDroitEstAcquisRetrocompatible(): void
    {
        unset($_ENV['SENDLY_AI_ENTITLED'], $_SERVER['SENDLY_AI_ENTITLED']);
        putenv('SENDLY_AI_ENTITLED');
        $_ENV['SENDLY_ANTHROPIC_KEY'] = 'cle-de-test';

        $service = $this->service();
        self::assertTrue($service->isEntitled());
        self::assertFalse($service->isTeaser());
        self::assertTrue($service->isEnabled());
    }

    public function testPlanGratuitTeaserMemeAvecUneCle(): void
    {
        $_ENV['SENDLY_AI_ENTITLED']   = '0';
        $_ENV['SENDLY_ANTHROPIC_KEY'] = 'cle-de-test';

        $service = $this->service();
        self::assertFalse($service->isEntitled());
        self::assertTrue($service->isTeaser());
        self::assertFalse($service->isEnabled(), 'une clé qui traîne ne doit JAMAIS ouvrir l IA à un plan gratuit');
    }

    public function testNiCleNiVariableRestentMasques(): void
    {
        unset($_ENV['SENDLY_AI_ENTITLED'], $_SERVER['SENDLY_AI_ENTITLED'], $_ENV['SENDLY_ANTHROPIC_KEY'], $_SERVER['SENDLY_ANTHROPIC_KEY']);
        putenv('SENDLY_AI_ENTITLED');
        putenv('SENDLY_ANTHROPIC_KEY');

        $service = $this->service();
        self::assertFalse($service->isEnabled());
        self::assertFalse($service->isTeaser(), 'sans directive du portail, le comportement actuel (surface ABSENTE) est préservé');
    }

    public function testLeControleurRefuseLeTeaserEn403(): void
    {
        $controller = (string) file_get_contents(__DIR__.'/../../Controller/AiController.php');

        // 403 entitlement ≠ 503 disabled : le front distingue « à
        // débloquer » de « pas configuré ». La garde teaser passe AVANT.
        self::assertSame(2, substr_count($controller, "return new JsonResponse(['error' => 'entitlement'], Response::HTTP_FORBIDDEN);"));
    }

    public function testLeSubscriberSertLaConfigTeaserSansAucunEndpoint(): void
    {
        $subscriber = (string) file_get_contents(__DIR__.'/../../EventSubscriber/AiConfigAssetsSubscriber.php');

        self::assertStringContainsString('isTeaser()', $subscriber);
        self::assertStringContainsString("'teaser'     => true", $subscriber);
        self::assertStringContainsString("'upgradeUrl'", $subscriber);
        self::assertStringContainsString('saas_portal_url', $subscriber);
        // La branche teaser NE transmet AUCUN endpoint IA (fenêtre bornée
        // au `return;` qui clôt la branche).
        $debut  = (int) strpos($subscriber, 'isTeaser()');
        $fin    = (int) strpos($subscriber, 'return;', $debut);
        $teaser = substr($subscriber, $debut, $fin - $debut);
        self::assertStringNotContainsString('eweb_ai_generate', $teaser);
    }

    public function testLesQuatreSurfacesOuvrentLUpsellEnTeaser(): void
    {
        $upsell = (string) file_get_contents(__DIR__.'/../../Assets/js/ai-upsell.js');
        self::assertStringContainsString('window.SendlyAiUpsell = { ouvrir: ouvrir, fermer: fermer };', $upsell);
        self::assertStringContainsString('upgradeUrl', $upsell);
        // Le design DA validé le 14/08 : héros vague + pilule verre dépoli,
        // typo Helvena servie même-origine, CTA « Essayez Pro » — des assets
        // du plugin, jamais du portail (pas de CORS côté portail).
        self::assertStringContainsString('Passez à la vitesse', $upsell);
        self::assertStringContainsString('Essayez Pro pendant 14 jours', $upsell);
        // Raffinements validés le 14/08 : titres contextuels par porte
        // d'entrée, réassurance sous le CTA, marque déposée sur la pilule.
        foreach (['email:', 'page:', 'segment:', 'aide:'] as $contexte) {
            self::assertStringContainsString($contexte, $upsell, 'titre contextuel manquant : '.$contexte);
        }
        self::assertStringContainsString("14 jours d\\'essai, sans engagement.", $upsell);
        self::assertStringContainsString('marque-r', $upsell);
        self::assertStringContainsString('/plugins/EwebAiBundle/Assets', $upsell);
        self::assertStringContainsString('Helvena', $upsell);
        self::assertStringContainsString('copilot-vague.jpg', $upsell);
        foreach (['fonts/Helvena_Light.woff2', 'fonts/Helvena_Medium.woff2', 'fonts/Helvena_Bold.woff2', 'img/copilot-vague.jpg', 'img/copilot-grain.jpg'] as $asset) {
            self::assertFileExists(__DIR__.'/../../Assets/'.$asset, 'asset du design manquant : '.$asset);
        }

        // Tuile du builder : visible en teaser, clic ET dépôt → upsell.
        $tuile = (string) file_get_contents(__DIR__.'/../../../EwebSaasBundle/Assets/js/builder-composants.js');
        self::assertStringContainsString('window.SendlyAiConfig.enabled || window.SendlyAiConfig.teaser', $tuile);
        $page = (string) file_get_contents(__DIR__.'/../../Assets/js/ai-page-builder.js');
        self::assertSame(2, substr_count($page, "window.SendlyAiUpsell.ouvrir('page')"));

        // E-mails : bouton visible, action verrouillée, contexte e-mail.
        $copilot = (string) file_get_contents(__DIR__.'/../../Assets/js/ai-copilot.js');
        self::assertStringContainsString('window.SendlyAiConfig.teaser', $copilot);
        self::assertStringContainsString("window.SendlyAiUpsell.ouvrir('email')", $copilot);

        // Lanceur de l'aide (couvre aussi les segments) : visible, verrouillé.
        $assistant = (string) file_get_contents(__DIR__.'/../../Assets/js/ai-assistant.js');
        self::assertStringContainsString('teaserActif()', $assistant);
        self::assertStringContainsString("window.SendlyAiUpsell.ouvrir('aide')", $assistant);
    }
}
