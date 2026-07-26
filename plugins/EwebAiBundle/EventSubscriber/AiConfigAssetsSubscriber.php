<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Mautic\InstallBundle\Install\InstallService;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Injecte le drapeau de configuration du copilote côté navigateur — et
 * UNIQUEMENT si une clé Anthropic est configurée (isEnabled()). Sans clé,
 * `window.SendlyAiConfig` reste undefined : le handler d'objet (ai-copilot.js,
 * auto-agrégé dans app.js) et le bouton de l'éditeur GrapesJS ne s'attachent
 * à rien. La surface IA est donc ABSENTE, pas seulement masquée.
 *
 * Seuls un booléen et l'URL de l'endpoint transitent — jamais la clé API.
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

        if (!$this->copilot->isEnabled()) {
            return;
        }

        $config = [
            'enabled'         => true,
            'endpoint'        => $this->router->generate('eweb_ai_generate', [], UrlGeneratorInterface::ABSOLUTE_PATH),
            'segmentEndpoint' => $this->router->generate('eweb_ai_segment_suggest', [], UrlGeneratorInterface::ABSOLUTE_PATH),
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
