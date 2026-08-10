<?php

declare(strict_types=1);

namespace MauticPlugin\GrapesJsBuilderBundle\EventSubscriber;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Mautic\InstallBundle\Install\InstallService;
use MauticPlugin\GrapesJsBuilderBundle\Integration\Config;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AssetsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Config $config,
        private InstallService $installer,
        private RequestStack $requestStack,
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
        if (!$this->installer->checkIfInstalled() || !$this->isMauticAdministrationPage()) {
            return;
        }

        if ($this->config->isPublished()) {
            $assetsEvent->addScript('plugins/GrapesJsBuilderBundle/Assets/library/js/dist/builder.js');
            $assetsEvent->addStylesheet('plugins/GrapesJsBuilderBundle/Assets/library/js/dist/builder.css');
            // Thème Sendly de la refonte (chantier D) : servi HORS du bundle
            // Parcel — l'itération design ne demande aucun npm run build.
            // Scopé .gjs-mode-page (classe posée par builder-shell.js) :
            // l'éditeur d'e-mails reste intact.
            $assetsEvent->addStylesheet('plugins/GrapesJsBuilderBundle/Assets/css/builder-sendly.css');
        }
    }

    /**
     * Returns true for routes that starts with /s/.
     */
    private function isMauticAdministrationPage(): bool
    {
        return preg_match('/^\/s\//', $this->requestStack->getCurrentRequest()->getPathInfo()) >= 1;
    }
}
