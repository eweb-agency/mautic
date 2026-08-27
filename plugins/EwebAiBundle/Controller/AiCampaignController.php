<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Controller;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Envoie l'e-mail X au segment Y jeudi 9 h » — monté sur
 * /s/ai/campaign/create (lot 5 de l'assistant exécutant, audit du 27/08).
 *
 * Périmètre VOLONTAIREMENT étroit : UN segment déclencheur → UN envoi
 * d'e-mail. Le canvas de campagne est la machinerie la plus fragile de
 * Mautic — le canvas posé ici est la forme CANONIQUE des tests du cœur
 * (nodes lists→événement, ancres leadsource→top), jamais une génération
 * libre. Tout enchaînement plus riche reste un geste humain.
 *
 * Doctrine des créations (précédents : abtest, formulaires) :
 *  - garde de l'écran natif (campaign:campaigns:create) ;
 *  - la MÊME barrière de validation que l'action de l'assistant
 *    (validateCampaignSpec) ;
 *  - l'e-mail et le segment sont RÉSOLUS sur les vraies entités par leur
 *    nom (exact d'abord, puis contient s'il est unique) — introuvable ou
 *    ambigu = refus dit, jamais une invention ;
 *  - la campagne naît DÉPUBLIÉE : rien ne part tant que l'utilisateur
 *    n'a pas relu le canvas et publié lui-même.
 */
final class AiCampaignController
{
    /** L'horaire d'envoi doit rester dans l'année (garde-fou de saisie). */
    private const SEND_AT_MAX_DAYS = 365;

    public function __construct(
        private readonly AiCopilotService $copilot,
        private readonly CampaignModel $campaignModel,
        private readonly EmailModel $emailModel,
        private readonly ListModel $listModel,
        private readonly CorePermissions $security,
        private readonly CoreParametersHelper $params,
    ) {
    }

    public function createAction(Request $request): JsonResponse
    {
        if (!$this->copilot->isEnabled()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->security->isGranted('campaign:campaigns:create')) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $spec = $this->copilot->validateCampaignSpec($this->decode($request));
        if (null === $spec) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $email = $this->resolveByName($this->emailModel->getRepository(), $spec['email']);
        if (!is_object($email)) {
            return new JsonResponse(['error' => str_replace('entity', 'email', $email), 'ref' => $spec['email']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $segment = $this->resolveByName($this->listModel->getRepository(), $spec['segment']);
        if (!is_object($segment)) {
            return new JsonResponse(['error' => str_replace('entity', 'segment', $segment), 'ref' => $spec['segment']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // L'horaire : fuseau de l'instance, strictement futur, borné à un an.
        $triggerDate = null;
        if (isset($spec['send_at'])) {
            try {
                $tz          = new \DateTimeZone($this->params->get('default_timezone') ?: 'UTC');
                $triggerDate = new \DateTimeImmutable($spec['send_at'], $tz);
            } catch (\Throwable) {
                return new JsonResponse(['error' => 'bad_send_at', 'ref' => $spec['send_at']], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $now = new \DateTimeImmutable('now', $triggerDate->getTimezone());
            if ($triggerDate <= $now || $triggerDate > $now->modify('+'.self::SEND_AT_MAX_DAYS.' days')) {
                return new JsonResponse(['error' => 'bad_send_at', 'ref' => $spec['send_at']], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $campaign = new Campaign();
        $campaign->setName($spec['name']);
        // DÉPUBLIÉE à la naissance : l'utilisateur relit le canvas et publie.
        $campaign->setIsPublished(false);
        $campaign->addList($segment);

        $event = new Event();
        $event->setName($email->getName());
        $event->setType('email.send');
        $event->setEventType('action');
        $event->setChannel('email');
        $event->setChannelId((int) $email->getId());
        // 'marketing' : un envoi de campagne respecte les règles de fréquence
        // et ne repart pas deux fois vers le même contact.
        $event->setProperties(['email' => (int) $email->getId(), 'email_type' => 'marketing']);
        if (null !== $triggerDate) {
            $event->setTriggerMode('date');
            $event->setTriggerDate(\DateTime::createFromImmutable($triggerDate));
        } else {
            $event->setTriggerMode('immediate');
        }
        $event->setCampaign($campaign);
        $campaign->addEvent(0, $event);

        try {
            // Première sauvegarde : les identifiants naissent ici ; le canvas
            // canonique (forme des tests du cœur) se pose ensuite avec le
            // VRAI identifiant d'événement, puis seconde sauvegarde.
            $this->campaignModel->saveEntity($campaign);
            $campaign->setCanvasSettings([
                'nodes' => [
                    ['id' => 'lists', 'positionX' => 360, 'positionY' => 50],
                    ['id' => $event->getId(), 'positionX' => 360, 'positionY' => 220],
                ],
                'connections' => [
                    [
                        'sourceId' => 'lists',
                        'targetId' => $event->getId(),
                        'anchors'  => ['source' => 'leadsource', 'target' => 'top'],
                    ],
                ],
            ]);
            $this->campaignModel->saveEntity($campaign, false);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'save_failed'], Response::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse(['id' => $campaign->getId()]);
    }

    /**
     * Résout une entité par son NOM : exact (sans casse) d'abord, sinon
     * « contient » s'il n'y a qu'UNE correspondance. Introuvable → la chaîne
     * 'entity_not_found' ; ambigu → 'entity_ambiguous'.
     *
     * @param object $repository un repository Doctrine avec createQueryBuilder
     */
    private function resolveByName(object $repository, string $ref): object|string
    {
        $exact = $repository->createQueryBuilder('e')
            ->where('LOWER(e.name) = :nom')->setParameter('nom', mb_strtolower($ref))
            ->setMaxResults(2)->getQuery()->getResult();
        if (1 === count($exact)) {
            return $exact[0];
        }
        if (count($exact) > 1) {
            return 'entity_ambiguous';
        }

        $partiel = $repository->createQueryBuilder('e')
            ->where('LOWER(e.name) LIKE :nom')->setParameter('nom', '%'.mb_strtolower($ref).'%')
            ->setMaxResults(2)->getQuery()->getResult();
        if (1 === count($partiel)) {
            return $partiel[0];
        }

        return count($partiel) > 1 ? 'entity_ambiguous' : 'entity_not_found';
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Request $request): array
    {
        $raw = trim((string) $request->getContent());

        if ('' !== $raw && str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }
}
