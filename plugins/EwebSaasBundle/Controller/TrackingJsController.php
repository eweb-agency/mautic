<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Controller;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\BuildJsEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Script de suivi servi sous un nom NEUTRE (marque blanche).
 *
 * LE PROBLÈME. Le client copie un extrait de code sur SON site, et cet
 * extrait exposait publiquement le moteur : le chemin `/mtc.js`, la variable
 * `window.MauticTrackingObject` et l'alias global `mt`. C'était la seule fuite
 * de marque que le client PUBLIE lui-même — sur l'écran censé prouver
 * l'intégration. Incompatible avec une offre revendue en marque blanche.
 *
 * LA SOLUTION. Cette route sert le même script, sous `/sendly.js`, en posant
 * elle-même la variable interne que le script attend. Conséquence : l'extrait
 * que le client colle ne contient plus que des noms neutres —
 *
 *     w['sendly'] = …file d'attente…
 *     …charge {domaine-du-client}/sendly.js…
 *     sendly('send', 'pageview');
 *
 * Le mécanisme repose sur un point précis du cœur : le script assemblé lit
 * `window[window.MauticTrackingObject]` pour récupérer la file d'attente
 * empilée par l'extrait (CoreBundle/EventListener/BuildJsSubscriber). En
 * posant cette variable AVANT le corps du script, la file est retrouvée sous
 * le nom neutre et le suivi fonctionne à l'identique.
 *
 * LICENCE — À NE PAS « NETTOYER ». L'en-tête de copyright et de licence du
 * script d'origine est reproduit tel quel : Mautic est distribué sous GPLv3,
 * qui impose de conserver les notices de licence dans ce qu'on redistribue.
 * Cet en-tête n'est visible qu'en ouvrant le fichier de script ; il n'apparaît
 * pas dans le code que le client colle sur ses pages. La neutralité de marque
 * s'arrête là où commence l'obligation légale.
 *
 * La route `/mtc.js` d'origine reste servie : les clients qui ont déjà collé
 * l'ancien extrait ne doivent pas voir leur suivi tomber.
 */
final class TrackingJsController
{
    /**
     * Nom de l'objet global exposé au client. Doit correspondre au `n` de
     * l'extrait généré par le portail (`buildTrackingSnippet`).
     */
    public const TRACKING_OBJECT = 'sendly';

    /**
     * `KernelInterface` plutôt que le paramètre `kernel.debug` en argument
     * d'action : l'injection d'un scalaire sur un argument de contrôleur exige
     * le tag `controller.service_arguments`, qu'un contrôleur de plugin sans
     * héritage n'a pas forcément. Une dépendance autowirée au constructeur est
     * garantie de fonctionner.
     */
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function indexAction(): Response
    {
        // Cette requête ne doit pas créer de visiteur (idem JsController).
        defined('MAUTIC_NON_TRACKABLE_REQUEST') || define('MAUTIC_NON_TRACKABLE_REQUEST', 1);

        $event = new BuildJsEvent($this->jsHeader(), $this->kernel->isDebug());

        if ($this->dispatcher->hasListeners(CoreEvents::BUILD_MAUTIC_JS)) {
            $this->dispatcher->dispatch($event, CoreEvents::BUILD_MAUTIC_JS);
        }

        // La variable interne est posée AVANT le corps du script : celui-ci y
        // lira le nom sous lequel l'extrait client a empilé sa file d'attente.
        $js = sprintf(
            "window.MauticTrackingObject = %s;\n%s",
            json_encode(self::TRACKING_OBJECT, JSON_THROW_ON_ERROR),
            $event->getJs()
        );

        return new Response($js, 200, ['Content-Type' => 'application/javascript']);
    }

    /**
     * En-tête de licence du script d'origine, reproduit à l'identique —
     * obligation GPLv3, voir la note de licence en tête de classe.
     */
    private function jsHeader(): string
    {
        $year = date('Y');

        return <<<JS
/**
 * @package     MauticJS
 * @copyright   {$year} Mautic Contributors. All rights reserved.
 * @author      Mautic
 * @link        http://mautic.org
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
JS;
    }
}
