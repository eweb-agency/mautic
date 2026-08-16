<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\InstallBundle\Install\InstallService;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Injecte les configurations du bundle côté navigateur — DEUX régimes :
 *
 *  - `window.SendlyAiConfig` (copilote, assistant de segments) : UNIQUEMENT si
 *    une clé Anthropic est configurée (isEnabled()). Sans clé, il reste
 *    undefined : le handler d'objet (ai-copilot.js, auto-agrégé dans app.js)
 *    et le bouton de l'éditeur GrapesJS ne s'attachent à rien. La surface IA
 *    est donc ABSENTE, pas seulement masquée.
 *  - `window.SendlySegmentCountConfig` (compteur en continu du formulaire de
 *    segment) : TOUJOURS injecté sur les pages admin. C'est de la valeur
 *    moteur, pas de l'IA — la clé n'a pas voix au chapitre.
 *
 * Seuls des booléens et des URL d'endpoints transitent — jamais la clé API.
 *
 * Calqué sur GrapesJsBuilderBundle/EventSubscriber/AssetsSubscriber.
 */
class AiConfigAssetsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AiCopilotService $copilot,
        private InstallService $installer,
        private RequestStack $requestStack,
        private RouterInterface $router,
        private CoreParametersHelper $params,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_ASSETS => ['injectAssets', 0],
        ];
    }

    public function injectAssets(CustomAssetsEvent $assetsEvent): void
    {
        if (!$this->installer->checkIfInstalled() || !$this->isAdministrationPage()) {
            return;
        }

        // Le compteur en continu du formulaire de segment n'est PAS une
        // surface IA : c'est le moteur qui compte, aucune clé n'intervient.
        // Sa configuration s'injecte donc AVANT la garde de clé — un tenant
        // sans copilote garde le nombre. Seul l'endpoint transite.
        $assetsEvent->addScriptDeclaration(
            'window.SendlySegmentCountConfig = '.json_encode(
                ['endpoint' => $this->router->generate('eweb_ai_segment_count', [], UrlGeneratorInterface::ABSOLUTE_PATH)],
                JSON_THROW_ON_ERROR
            ).';'
        );

        // IA NON ACTIVE — droit refusé OU clé absente (décision proprio
        // 16/08 : « on a créé la modale justement pour ça ») : les points
        // d'entrée restent VISIBLES et chaque surface ouvre l'écran Sendly
        // Copilot (ai-upsell.js). AUCUN endpoint IA ne transite dans ce
        // régime. L'état « aucune surface » n'existe plus.
        if ($this->copilot->isTeaser()) {
            $portail = rtrim((string) $this->params->get('saas_portal_url'), '/');
            $assetsEvent->addScriptDeclaration(
                'window.SendlyAiConfig = '.json_encode([
                    'enabled'    => false,
                    'teaser'     => true,
                    // L'intention du clic est CHANGER DE PLAN : atterrir sur l'onglet
                    // abonnement, pas sur la page organisation générique (retour
                    // proprio 16/08 — chaque écran intermédiaire perd de l'intention).
                    'upgradeUrl' => '' !== $portail ? $portail.'/dashboard/organization/settings?tab=subscription' : '',
                ], JSON_THROW_ON_ERROR).';'
            );

            return;
        }

        // Ici, isTeaser() faux ⇒ isEnabled() vrai : l'IA est active.
        $config = [
            'enabled'         => true,
            'endpoint'        => $this->router->generate('eweb_ai_generate', [], UrlGeneratorInterface::ABSOLUTE_PATH),
            'segmentEndpoint' => $this->router->generate('eweb_ai_segment_suggest', [], UrlGeneratorInterface::ABSOLUTE_PATH),
            'assistEndpoint'  => $this->router->generate('eweb_ai_assist', [], UrlGeneratorInterface::ABSOLUTE_PATH),
            'abtestEndpoint'  => $this->router->generate('eweb_ai_email_abtest', [], UrlGeneratorInterface::ABSOLUTE_PATH),
        ];

        $assetsEvent->addScriptDeclaration(
            'window.SendlyAiConfig = '.json_encode($config, JSON_THROW_ON_ERROR).';'
        );
    }

    /**
     * Vrai pour les routes admin qui commencent par /s/.
     */
    private function isAdministrationPage(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && 1 === preg_match('/^\/s\//', $request->getPathInfo());
    }
}
