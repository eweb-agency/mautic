<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Controller;

use Mautic\EmailBundle\Controller\PublicController;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\DoNotContact as Dnc;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PageBundle\Entity\Page;
use MauticPlugin\EwebSaasBundle\Controller\EmailPreferencesController;
use MauticPlugin\EwebSaasBundle\Service\EmailPreferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EmailPreferencesControllerTest extends TestCase
{
    private const HASH    = 'hachage123';

    private const ADDRESS = 'camille@exemple.fr';

    private EmailPreferences&MockObject $preferences;

    private Environment&MockObject $twig;

    private HttpKernelInterface&MockObject $kernel;

    private EmailPreferencesController $controller;

    /**
     * @var array<string, mixed>
     */
    private array $rendered = [];

    protected function setUp(): void
    {
        $this->preferences = $this->createMock(EmailPreferences::class);
        $this->twig        = $this->createMock(Environment::class);
        $this->kernel      = $this->createMock(HttpKernelInterface::class);

        $this->twig->method('render')->willReturnCallback(function (string $template, array $parameters): string {
            $this->rendered = $parameters;

            return 'page';
        });

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $route, array $parameters): string {
            $path = sprintf('/%s/%s', $route, $parameters['idHash']);

            return isset($parameters['screen']) && 'home' !== $parameters['screen'] ? $path.'/'.$parameters['screen'] : $path;
        });

        $this->controller = new EmailPreferencesController(
            $this->preferences,
            $this->twig,
            $this->createMock(TranslatorInterface::class),
            $router,
            $this->kernel,
        );
    }

    public function testLeLienDeLEmailMeneAUneAdresseSansLeContact(): void
    {
        $this->givenStat($this->stat());
        $this->kernel->expects(self::never())->method('handle');

        $response = $this->controller->entryAction($this->entryRequest(), self::HASH);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/eweb_saas_email_preferences/'.self::HASH, $response->headers->get('Location'));
        self::assertStringNotContainsString(self::ADDRESS, (string) $response->headers->get('Location'));
        self::assertStringNotContainsString('@', (string) $response->headers->get('Location'));
    }

    public function testArriverSurLeLienNeDesabonnePersonne(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->expects(self::never())->method('unsubscribe');

        $this->controller->entryAction($this->entryRequest(), self::HASH);
    }

    public function testUnCentreDePreferencesRendLaMainAuParcoursDOrigine(): void
    {
        $page = new Page();
        $page->setIsPreferenceCenter(true);
        $page->setCustomHtml('<p>{saveprefsbutton}</p>');

        $email = new Email();
        $email->setPreferenceCenter($page);

        $this->givenStat($this->stat($email));
        $this->expectOriginalPage('sendly_preference_center');

        $this->controller->entryAction($this->entryRequest(), self::HASH);
    }

    public function testUnCentreDePreferencesSansBoutonNeRemplacePasLaPage(): void
    {
        $page = new Page();
        $page->setIsPreferenceCenter(true);
        $page->setCustomHtml('<p>Sans bouton</p>');

        $email = new Email();
        $email->setPreferenceCenter($page);

        $this->givenStat($this->stat($email));
        $this->kernel->expects(self::never())->method('handle');

        self::assertTrue($this->controller->entryAction($this->entryRequest(), self::HASH)->isRedirect());
    }

    public function testUnFormulaireDeDesabonnementGardeSonParcours(): void
    {
        $form = new Form();
        $form->setIsPublished(true);

        $email = new Email();
        $email->setUnsubscribeForm($form);

        $this->givenStat($this->stat($email));
        $this->expectOriginalPage('unsubscribe_all');

        $this->controller->entryAction($this->entryRequest(), self::HASH);
    }

    public function testUnFormulaireNonPublieNeChangeRien(): void
    {
        $form = new Form();
        $form->setIsPublished(false);

        $email = new Email();
        $email->setUnsubscribeForm($form);

        $this->givenStat($this->stat($email));
        $this->kernel->expects(self::never())->method('handle');

        self::assertTrue($this->controller->entryAction($this->entryRequest(), self::HASH)->isRedirect());
    }

    public function testSansStatLeParcoursDOrigineGardeLaVerificationDuHachage(): void
    {
        $this->givenStat(null);
        $this->expectOriginalPage('unsubscribe_all');

        $this->controller->entryAction($this->entryRequest(), self::HASH);
    }

    public function testLEcranDAccueilNeChangeRien(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);
        $this->preferences->expects(self::never())->method('unsubscribe');
        $this->preferences->expects(self::never())->method('saveRhythm');

        $response = $this->controller->showAction(self::HASH);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('home', $this->rendered['screen']);
        self::assertSame(self::ADDRESS, $this->rendered['address']);
    }

    public function testAucuneUrlDesEcransNeContientLAdresse(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);

        $this->controller->showAction(self::HASH);

        self::assertNotEmpty($this->rendered['urls']);

        foreach ($this->rendered['urls'] as $url) {
            self::assertStringContainsString(self::HASH, $url);
            self::assertStringNotContainsString('@', $url);
        }
    }

    public function testLesEcransNeSontNiCachesNiIndexesEtNEmportentPasLeLien(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);

        foreach ([$this->controller->showAction(self::HASH), $this->controller->entryAction($this->entryRequest(), self::HASH)] as $response) {
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            self::assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        }
    }

    public function testLeRythmeEnregistreEstPreselectionne(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);
        $this->preferences->method('rhythm')->willReturn(EmailPreferences::RHYTHM_MONTHLY);

        $this->controller->showAction(self::HASH, 'frequency');

        self::assertSame('frequency', $this->rendered['screen']);
        self::assertSame(EmailPreferences::RHYTHM_MONTHLY, $this->rendered['rhythm']);
    }

    public function testSansRythmeLaSemaineEstPreselectionnee(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);
        $this->preferences->method('rhythm')->willReturn(null);

        $this->controller->showAction(self::HASH, 'frequency');

        self::assertSame(EmailPreferences::RHYTHM_WEEKLY, $this->rendered['rhythm']);
    }

    public function testLaConfirmationAfficheLeRythmeEnregistre(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);
        $this->preferences->method('rhythm')->willReturn(EmailPreferences::RHYTHM_WEEKLY);

        $this->controller->showAction(self::HASH, 'saved');

        self::assertSame('saved', $this->rendered['screen']);
        self::assertSame(EmailPreferences::RHYTHM_WEEKLY, $this->rendered['rhythm']);
    }

    public function testSansRythmeLaConfirmationRenvoieAuChoix(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);
        $this->preferences->method('rhythm')->willReturn(null);

        $response = $this->controller->showAction(self::HASH, 'saved');

        self::assertTrue($response->isRedirect('/eweb_saas_email_preferences/'.self::HASH.'/frequency'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ecrans(): iterable
    {
        foreach (['home', 'frequency', 'saved', 'resubscribed'] as $screen) {
            yield $screen => [$screen];
        }
    }

    #[DataProvider('ecrans')]
    public function testUnContactDesabonneVoitToujoursSonEtatReel(string $screen): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::UNSUBSCRIBED);

        $this->controller->showAction(self::HASH, $screen);

        self::assertSame('unsubscribed', $this->rendered['screen']);
        self::assertTrue($this->rendered['canResubscribe']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function autresBlocages(): iterable
    {
        yield 'rebond' => [Dnc::BOUNCED];
        yield 'blocage manuel' => [Dnc::MANUAL];
    }

    #[DataProvider('autresBlocages')]
    public function testLeReabonnementNEstProposeQuApresUnDesabonnement(int $reason): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn($reason);

        $this->controller->showAction(self::HASH);

        self::assertSame('unsubscribed', $this->rendered['screen']);
        self::assertFalse($this->rendered['canResubscribe']);
    }

    public function testEnregistrerLeRythmeRenvoieALaConfirmation(): void
    {
        $stat = $this->stat();
        $this->givenStat($stat);
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);
        $this->preferences->method('isRhythm')->willReturn(true);
        $this->preferences->expects(self::once())->method('saveRhythm')->with($stat->getLead(), 'monthly');

        $response = $this->controller->frequencyAction($this->post(['rhythm' => 'monthly']), self::HASH);

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame('/eweb_saas_email_preferences/'.self::HASH.'/saved', $response->headers->get('Location'));
    }

    public function testUnChoixInconnuNEnregistreRien(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);
        $this->preferences->method('isRhythm')->willReturn(false);
        $this->preferences->expects(self::never())->method('saveRhythm');

        $response = $this->controller->frequencyAction($this->post(['rhythm' => 'pause']), self::HASH);

        self::assertSame('/eweb_saas_email_preferences/'.self::HASH.'/frequency', $response->headers->get('Location'));
    }

    public function testUnContactDesabonneNePosePasDeRythme(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('dncReason')->willReturn(Dnc::UNSUBSCRIBED);
        $this->preferences->method('isRhythm')->willReturn(true);
        $this->preferences->expects(self::never())->method('saveRhythm');

        $this->controller->frequencyAction($this->post(['rhythm' => 'weekly']), self::HASH);
    }

    public function testLeContactVientDuHachageJamaisDuFormulaire(): void
    {
        $stat = $this->stat();
        $this->givenStat($stat);
        $this->preferences->method('dncReason')->willReturn(Dnc::IS_CONTACTABLE);
        $this->preferences->method('isRhythm')->willReturn(true);
        $this->preferences->expects(self::once())->method('saveRhythm')->with(self::identicalTo($stat->getLead()), 'weekly');

        $this->controller->frequencyAction($this->post(['rhythm' => 'weekly', 'lead' => 999, 'email' => 'autre@exemple.fr']), self::HASH);
    }

    public function testSeDesabonnerRevientSurLaPageDeLEtatReel(): void
    {
        $stat = $this->stat();
        $this->givenStat($stat);
        $this->preferences->expects(self::once())->method('unsubscribe')->with($stat);

        $response = $this->controller->unsubscribeAction(self::HASH);

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame('/eweb_saas_email_preferences/'.self::HASH, $response->headers->get('Location'));
    }

    public function testSeReabonnerConfirmeLeRetour(): void
    {
        $stat = $this->stat();
        $this->givenStat($stat);
        $this->preferences->expects(self::once())->method('resubscribe')->with($stat->getLead())->willReturn(true);

        $response = $this->controller->resubscribeAction(self::HASH);

        self::assertSame('/eweb_saas_email_preferences/'.self::HASH.'/resubscribed', $response->headers->get('Location'));
    }

    public function testUnReabonnementRefuseNeConfirmeRien(): void
    {
        $this->givenStat($this->stat());
        $this->preferences->method('resubscribe')->willReturn(false);

        $response = $this->controller->resubscribeAction(self::HASH);

        self::assertSame('/eweb_saas_email_preferences/'.self::HASH, $response->headers->get('Location'));
    }

    public function testUnHachageInconnuNeMeneANiAucuneEcriture(): void
    {
        $this->givenStat(null);
        $this->preferences->expects(self::never())->method('unsubscribe');
        $this->preferences->expects(self::never())->method('saveRhythm');
        $this->preferences->expects(self::never())->method('resubscribe');

        $responses = [
            $this->controller->showAction('inconnu'),
            $this->controller->frequencyAction($this->post(['rhythm' => 'weekly']), 'inconnu'),
            $this->controller->unsubscribeAction('inconnu'),
            $this->controller->resubscribeAction('inconnu'),
        ];

        foreach ($responses as $response) {
            self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        }

        self::assertSame('invalid', $this->rendered['screen']);
        self::assertArrayNotHasKey('address', $this->rendered);
    }

    private function givenStat(?Stat $stat): void
    {
        $this->preferences->method('findStat')->willReturnCallback(static fn (string $idHash): ?Stat => self::HASH === $idHash ? $stat : null);
    }

    private function stat(?Email $email = null): Stat
    {
        $stat = new Stat();
        $stat->setTrackingHash(self::HASH);
        $stat->setEmailAddress(self::ADDRESS);
        $stat->setLead(new Lead());

        if ($email) {
            $stat->setEmail($email);
        }

        return $stat;
    }

    private function expectOriginalPage(string $flag): void
    {
        $this->kernel->expects(self::once())->method('handle')->willReturnCallback(
            static function (Request $request, int $type) use ($flag): Response {
                self::assertSame(HttpKernelInterface::SUB_REQUEST, $type);
                self::assertSame(PublicController::class.'::unsubscribeAction', $request->attributes->get('_controller'));
                self::assertSame(1, $request->attributes->get($flag));
                self::assertSame(self::HASH, $request->attributes->get('idHash'));
                self::assertSame(self::ADDRESS, $request->attributes->get('urlEmail'));

                return new Response('page d\'origine');
            }
        );
    }

    private function entryRequest(): Request
    {
        $request = Request::create('/email/unsubscribe/'.self::HASH.'/'.self::ADDRESS.'/signature');
        $request->attributes->add(['idHash' => self::HASH, 'urlEmail' => self::ADDRESS, 'secretHash' => 'signature']);

        return $request;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function post(array $data): Request
    {
        return Request::create('/', 'POST', $data);
    }
}
