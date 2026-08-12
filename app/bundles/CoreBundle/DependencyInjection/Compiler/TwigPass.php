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
                // La release (unique par build d'image, écrite dans app/release.txt
                // par le Dockerfile) entre dans le hash du ?v des assets : sans elle,
                // le ?v ne change qu'aux montées de version Mautic et les navigateurs
                // servent l'ANCIEN app.js agrégé après chaque déploiement. Un FICHIER
                // et non getenv : la compilation du conteneur se fait chez chaque
                // tenant DANS UNE REQUÊTE WEB (le ?v diffère par tenant, constaté),
                // et Apache/mod_php ne transmet pas l'environnement du conteneur —
                // getenv y est vide (constaté : ?v inchangé sur 2 déploiements).
                // Fichier absent = comportement d'origine.
                ->addMethodCall('setVersion', ['%mautic.secret_key%', MAUTIC_VERSION.self::release()]);
        }
    }

    private static function release(): string
    {
        $fichier = dirname(__DIR__, 4).'/release.txt';

        return is_readable($fichier) ? trim((string) file_get_contents($fichier)) : '';
    }
}
