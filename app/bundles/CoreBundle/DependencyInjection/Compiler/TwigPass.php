<?php

namespace Mautic\CoreBundle\DependencyInjection\Compiler;

use Mautic\CoreBundle\Twig\Helper\AssetsHelper;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class TwigPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(AssetsHelper::class)) {
            $container->getDefinition(AssetsHelper::class)
                ->addMethodCall('setPathsHelper', [new Reference('mautic.helper.paths')])
                ->addMethodCall('setAssetHelper', [new Reference('mautic.helper.assetgeneration')])
                ->addMethodCall('setBuilderIntegrationsHelper', [new Reference('mautic.integrations.helper.builder_integrations')])
                ->addMethodCall('setInstallService', [new Reference('mautic.install.service')])
                ->addMethodCall('setSiteUrl', ['%mautic.site_url%'])
                // SENDLY_RELEASE (posé par l'image Docker, unique par build) entre
                // dans le hash du ?v des assets : sans lui, le ?v ne change qu'aux
                // montées de version Mautic et les navigateurs servent l'ANCIEN
                // app.js agrégé après chaque déploiement (cache heuristique — le
                // serveur n'envoyait aucun en-tête). Évalué à la COMPILATION du
                // conteneur : l'image le fige au build, un env absent = comportement
                // d'origine.
                ->addMethodCall('setVersion', ['%mautic.secret_key%', MAUTIC_VERSION.(getenv('SENDLY_RELEASE') ?: '')]);
        }
    }
}
