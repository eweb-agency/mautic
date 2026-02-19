<?php

namespace Mautic\CoreBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Mautic\ConfigBundle\Event\ConfigEvent;
use Mautic\CoreBundle\Form\Type\ConfigType;
use Mautic\CoreBundle\Helper\LanguageHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Filesystem\Filesystem;

class ConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LanguageHelper $languageHelper,
        private PathsHelper $pathsHelper,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigEvents::CONFIG_ON_GENERATE => ['onConfigGenerate', 0],
            ConfigEvents::CONFIG_PRE_SAVE    => ['onConfigBeforeSave', 0],
        ];
    }

    public function onConfigGenerate(ConfigBuilderEvent $event): void
    {
        $coreParams = $event->getParametersFromConfig('MauticCoreBundle');
        unset($coreParams['theme']);
        unset($coreParams['theme_import_allowed_extensions']);
        $event->addForm([
            'bundle'     => 'CoreBundle',
            'formType'   => ConfigType::class,
            'formAlias'  => 'coreconfig',
            'formTheme'  => '@MauticCore/FormTheme/Config/config_layout.html.twig',
            'parameters' => $coreParams,
        ]);
    }

    public function onConfigBeforeSave(ConfigEvent $event): void
    {
        $values = $event->getConfig();

        // Preserve existing value
        $event->unsetIfEmpty('transifex_password');

        // Check if the selected locale has been downloaded already, fetch it if not
        if (!array_key_exists($values['coreconfig']['locale'], $this->languageHelper->getSupportedLanguages())) {
            $fetchLanguage = $this->languageHelper->extractLanguagePackage($values['coreconfig']['locale']);

            // If there is an error, fall back to 'en_US' as it is our system default
            if ($fetchLanguage['error']) {
                $values['coreconfig']['locale'] = 'en_US';
                $message                        = 'mautic.core.could.not.set.language';
                $messageVars                    = [];

                if (isset($fetchLanguage['message'])) {
                    $message = $fetchLanguage['message'];
                }

                if (isset($fetchLanguage['vars'])) {
                    $messageVars = $fetchLanguage['vars'];
                }

                $event->setError($message, $messageVars);
            } else {
                // Invalidate translation cache so Symfony regenerates catalogues for the new language
                $cacheDir = $this->pathsHelper->getSystemPath('cache').'/translations';
                if (is_dir($cacheDir)) {
                    (new Filesystem())->remove($cacheDir);
                }
            }
        }

        $event->setConfig($values);
    }
}
