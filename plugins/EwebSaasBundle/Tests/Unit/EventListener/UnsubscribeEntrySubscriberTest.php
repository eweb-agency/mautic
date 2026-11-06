<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use MauticPlugin\EwebSaasBundle\Controller\EmailPreferencesController;
use MauticPlugin\EwebSaasBundle\EventListener\UnsubscribeEntrySubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class UnsubscribeEntrySubscriberTest extends TestCase
{
    private const ORIGINAL = 'Mautic\EmailBundle\Controller\PublicController::unsubscribeAction';

    private const PAGE = EmailPreferencesController::class.'::entryAction';

    public function testEcouteLaRequeteApresLeRoutage(): void
    {
        // Priorité 0 : après le RouterListener (32), qui pose `_route`.
        self::assertSame('divertUnsubscribeLink', UnsubscribeEntrySubscriber::getSubscribedEvents()[KernelEvents::REQUEST]);
    }

    #[DataProvider('lectures')]
    public function testLeLienDeLEmailPasseParLaPage(string $method): void
    {
        $request = $this->request($method);

        $this->dispatch($request);

        self::assertSame(self::PAGE, $request->attributes->get('_controller'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lectures(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    /**
     * L'exigence non négociable : le POST de List-Unsubscribe-Post ne passe
     * jamais par la page, quel que soit le réglage.
     */
    public function testLeDesabonnementEnUnClicParEnTeteResteDirect(): void
    {
        $request = $this->request('POST', ['List-Unsubscribe' => 'One-Click']);

        $this->dispatch($request);

        self::assertSame(self::ORIGINAL, $request->attributes->get('_controller'));
    }

    public function testToutAutrePostResteAuParcoursDOrigine(): void
    {
        // Le centre de préférences d'un client poste sur cette même adresse.
        $request = $this->request('POST');

        $this->dispatch($request);

        self::assertSame(self::ORIGINAL, $request->attributes->get('_controller'));
    }

    public function testUneAutreRouteNEstPasTouchee(): void
    {
        $request = $this->request('GET');
        $request->attributes->set('_route', 'mautic_email_unsubscribe_all');

        $this->dispatch($request);

        self::assertSame(self::ORIGINAL, $request->attributes->get('_controller'));
    }

    public function testUneSousRequeteNEstPasDeviee(): void
    {
        // Le contrôleur rend la main au parcours d'origine par sous-requête :
        // la dévier à nouveau ferait une boucle.
        $request = $this->request('GET');

        $this->dispatch($request, HttpKernelInterface::SUB_REQUEST);

        self::assertSame(self::ORIGINAL, $request->attributes->get('_controller'));
    }

    public function testLeReglageCoupeLaDeviation(): void
    {
        $request = $this->request('GET');

        $this->dispatch($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(self::ORIGINAL, $request->attributes->get('_controller'));
    }

    /**
     * @param array<string, string> $post
     */
    private function request(string $method, array $post = []): Request
    {
        $request = Request::create('/email/unsubscribe/hash', $method, $post);
        $request->attributes->set('_route', 'mautic_email_unsubscribe');
        $request->attributes->set('_controller', self::ORIGINAL);

        return $request;
    }

    private function dispatch(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $enabled = true): void
    {
        $parameters = $this->createMock(CoreParametersHelper::class);
        $parameters->method('get')->with('saas_unsubscribe_page')->willReturn($enabled);

        (new UnsubscribeEntrySubscriber($parameters))->divertUnsubscribeLink(
            new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type)
        );
    }
}
