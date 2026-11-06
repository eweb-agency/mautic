<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Controller;

use Mautic\EmailBundle\Controller\PublicController;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\DoNotContact as Dnc;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\EventListener\BuilderSubscriber;
use MauticPlugin\EwebSaasBundle\Service\EmailPreferences;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Page de désabonnement vue par les destinataires : réduire la fréquence ou
 * se désabonner, sur tous les espaces.
 *
 * L'adresse ne figure dans aucune URL de ces écrans : le hachage de suivi de
 * l'e-mail reçu est la seule clé, et ce qui est affiché vient de lui seul.
 * Afficher un écran ne change rien (les robots qui suivent les liens des
 * e-mails ne désabonnent plus personne) : les écritures sont en POST, sans
 * jeton de session car le hachage est déjà un secret, comme pour le clic par
 * en-tête. Aucun cookie, aucun script, aucun suivi du visiteur.
 */
final class EmailPreferencesController
{
    public function __construct(
        private readonly EmailPreferences $preferences,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly UrlGeneratorInterface $router,
        private readonly HttpKernelInterface $kernel,
    ) {
    }

    /**
     * Lien de désabonnement d'un e-mail (GET, voir UnsubscribeEntrySubscriber).
     *
     * Le lien d'origine porte l'adresse du contact : on en sort aussitôt vers
     * une URL qui ne la contient pas. Les cas que la page ne couvre pas
     * gardent le comportement d'avant.
     */
    public function entryAction(Request $request, string $idHash): Response
    {
        $stat  = $this->preferences->findStat($idHash);
        $email = $stat?->getEmail();

        if ($this->hasPreferenceCenter($email)) {
            return $this->originalPage($request, 'sendly_preference_center');
        }

        if (null === $stat || $this->hasUnsubscribeForm($email)) {
            return $this->originalPage($request, 'unsubscribe_all');
        }

        return $this->secure(new RedirectResponse($this->url($idHash)));
    }

    public function showAction(string $idHash, string $screen = 'home'): Response
    {
        $stat = $this->stat($idHash);

        if (null === $stat) {
            return $this->invalidLink();
        }

        $lead = $stat->getLead();
        \assert($lead instanceof Lead);
        $this->useLocale($lead);

        $reason = $this->preferences->dncReason($lead);

        if (Dnc::IS_CONTACTABLE !== $reason) {
            return $this->render('unsubscribed', $stat, ['canResubscribe' => Dnc::UNSUBSCRIBED === $reason]);
        }

        $rhythm = $this->preferences->rhythm($lead);

        return match ($screen) {
            'frequency'    => $this->render('frequency', $stat, ['rhythm' => $rhythm ?? EmailPreferences::RHYTHM_WEEKLY]),
            'saved'        => null === $rhythm ? $this->redirect($idHash, 'frequency') : $this->render('saved', $stat, ['rhythm' => $rhythm]),
            'resubscribed' => $this->render('resubscribed', $stat),
            default        => $this->render('home', $stat),
        };
    }

    public function frequencyAction(Request $request, string $idHash): Response
    {
        $stat = $this->stat($idHash);

        if (null === $stat) {
            return $this->invalidLink();
        }

        $lead = $stat->getLead();
        \assert($lead instanceof Lead);

        if (Dnc::IS_CONTACTABLE !== $this->preferences->dncReason($lead)) {
            return $this->redirect($idHash);
        }

        $rhythm = (string) $request->request->get('rhythm');

        if (!$this->preferences->isRhythm($rhythm)) {
            return $this->redirect($idHash, 'frequency');
        }

        $this->preferences->saveRhythm($lead, $rhythm);

        return $this->redirect($idHash, 'saved');
    }

    public function unsubscribeAction(string $idHash): Response
    {
        $stat = $this->stat($idHash);

        if (null === $stat) {
            return $this->invalidLink();
        }

        $this->preferences->unsubscribe($stat);

        return $this->redirect($idHash);
    }

    public function resubscribeAction(string $idHash): Response
    {
        $stat = $this->stat($idHash);

        if (null === $stat) {
            return $this->invalidLink();
        }

        $lead = $stat->getLead();
        \assert($lead instanceof Lead);

        return $this->preferences->resubscribe($lead) ? $this->redirect($idHash, 'resubscribed') : $this->redirect($idHash);
    }

    private function stat(string $idHash): ?Stat
    {
        // Comme pour /sendly.js : cette requête ne crée pas de visiteur.
        defined('MAUTIC_NON_TRACKABLE_REQUEST') || define('MAUTIC_NON_TRACKABLE_REQUEST', 1);

        return $this->preferences->findStat($idHash);
    }

    /**
     * Même contrôle que `PublicController::unsubscribeAction` : sans le bouton
     * d'enregistrement, cette page ne servirait à rien au destinataire.
     */
    private function hasPreferenceCenter(?Email $email): bool
    {
        $page = $email?->getPreferenceCenter();

        return $page instanceof Page
            && $page->getIsPreferenceCenter()
            && str_contains((string) $page->getCustomHtml(), BuilderSubscriber::saveprefsRegex);
    }

    private function hasUnsubscribeForm(?Email $email): bool
    {
        return (bool) $email?->getUnsubscribeForm()?->isPublished();
    }

    /**
     * `unsubscribe_all` est l'attribut que pose déjà `unsubscribeAllAction`.
     */
    private function originalPage(Request $request, string $flag): Response
    {
        $attributes = array_merge($request->attributes->all(), [
            '_controller' => PublicController::class.'::unsubscribeAction',
            $flag         => 1,
        ]);

        return $this->kernel->handle($request->duplicate(null, null, $attributes), HttpKernelInterface::SUB_REQUEST);
    }

    private function useLocale(?Lead $lead): void
    {
        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($this->preferences->locale($lead));
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function render(string $screen, Stat $stat, array $parameters = []): Response
    {
        $idHash = (string) $stat->getTrackingHash();

        return $this->page(array_merge([
            'screen'  => $screen,
            'brand'   => $this->preferences->brandName($stat->getEmail()),
            'address' => $stat->getEmailAddress(),
            'urls'    => [
                'frequency'   => $this->url($idHash, 'frequency'),
                'save'        => $this->router->generate('eweb_saas_email_preferences_frequency', ['idHash' => $idHash]),
                'unsubscribe' => $this->router->generate('eweb_saas_email_preferences_unsubscribe', ['idHash' => $idHash]),
                'resubscribe' => $this->router->generate('eweb_saas_email_preferences_resubscribe', ['idHash' => $idHash]),
            ],
        ], $parameters));
    }

    private function invalidLink(): Response
    {
        $this->useLocale(null);

        return $this->page(['screen' => 'invalid', 'brand' => $this->preferences->brandName(null)], Response::HTTP_NOT_FOUND);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function page(array $parameters, int $status = Response::HTTP_OK): Response
    {
        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : 'fr';

        return $this->secure(new Response(
            $this->twig->render('@EwebSaas/EmailPreferences/page.html.twig', array_merge($parameters, ['language' => substr($locale, 0, 2)])),
            $status
        ));
    }

    private function url(string $idHash, string $screen = 'home'): string
    {
        return $this->router->generate('eweb_saas_email_preferences', ['idHash' => $idHash, 'screen' => $screen]);
    }

    private function redirect(string $idHash, string $screen = 'home'): Response
    {
        return $this->secure(new RedirectResponse($this->url($idHash, $screen), Response::HTTP_SEE_OTHER));
    }

    /**
     * Ces écrans affichent une adresse : ni cache partagé, ni référent qui
     * emporterait le lien d'origine, ni indexation.
     */
    private function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
