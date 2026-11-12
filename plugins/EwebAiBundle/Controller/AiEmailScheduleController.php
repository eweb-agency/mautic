<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Controller;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use MauticPlugin\EwebAiBundle\Service\EmailScheduleRules;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Programmer (ou annuler) l'envoi d'un e-mail de segment depuis Sendly Copilot.
 *
 * DEUX TEMPS, TOUJOURS. `confirm` absent : PRÉVISUALISATION, rien n'est écrit ;
 * le panneau affiche l'e-mail, ses segments, l'horaire et le nombre de
 * destinataires, avec un bouton « Confirmer ». `confirm` vrai (le clic) : la
 * même requête est revalidée de bout en bout, puis exécutée. Le modèle ne peut
 * donc jamais déclencher un envoi : seul le clic de l'utilisateur le fait.
 *
 * Programmer = publier avec une date de publication (voir EmailScheduleRules).
 * Annuler = dépublier : l'envoi planifié ne prend plus cet e-mail.
 */
final class AiEmailScheduleController
{
    public function __construct(
        private readonly AiCopilotService $copilot,
        private readonly EmailModel $emailModel,
        private readonly CorePermissions $security,
        private readonly AuditLogModel $auditLog,
        private readonly CoreParametersHelper $params,
    ) {
    }

    public function scheduleAction(Request $request): JsonResponse
    {
        if (!$this->copilot->isEnabled()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $payload = $this->decode($request);
        $ref     = trim((string) ($payload['email'] ?? ''));
        $id      = (int) ($payload['email_id'] ?? 0);
        $cancel  = filter_var($payload['cancel'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $confirm = filter_var($payload['confirm'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $email = $this->resolve($id, $ref);
        if (!$email instanceof Email) {
            return new JsonResponse(['error' => $email, 'ref' => $ref], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $owner = $email->getCreatedBy();
        if (!$this->security->hasEntityAccess('email:emails:viewown', 'email:emails:viewother', $owner)
            || !$this->security->hasEntityAccess('email:emails:publishown', 'email:emails:publishother', $owner)) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $tz  = new \DateTimeZone($this->params->get('default_timezone') ?: 'UTC');
        $now = new \DateTimeImmutable('now', $tz);

        $segments = [];
        foreach ($email->getLists() as $list) {
            $segments[] = (string) $list->getName();
        }
        $base = [
            'id'       => (int) $email->getId(),
            'name'     => (string) $email->getName(),
            'subject'  => (string) $email->getSubject(),
            'segments' => $segments,
        ];

        if ($cancel) {
            if (!$confirm) {
                return new JsonResponse($base + ['mode' => 'cancel', 'published' => (bool) $email->isPublished()]);
            }
            $email->setIsPublished(false);

            return $this->save($email) ?? new JsonResponse($base + ['mode' => 'cancel', 'done' => true]);
        }

        $sendAt = EmailScheduleRules::sendAt(isset($payload['send_at']) ? (string) $payload['send_at'] : null, $tz, $now);
        if (is_string($sendAt)) {
            return new JsonResponse(['error' => $sendAt, 'ref' => (string) ($payload['send_at'] ?? '')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $refus = EmailScheduleRules::refus($email, $sendAt);
        if (null !== $refus) {
            return new JsonResponse(['error' => $refus, 'ref' => $base['name']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $base += [
            'mode'          => 'schedule',
            'send_at'       => $sendAt->format('Y-m-d H:i'),
            'send_at_label' => EmailScheduleRules::libelle($sendAt, $now),
        ];

        if (!$confirm) {
            return new JsonResponse($base + ['recipients' => $this->recipients($email, $sendAt)]);
        }

        $email->setPublishUp(\DateTime::createFromImmutable($sendAt));
        $email->setIsPublished(true);
        $echec = $this->save($email);
        if (null !== $echec) {
            return $echec;
        }

        // Temps gagné, crédité CÔTÉ SERVEUR comme les autres créations.
        $this->auditLog->writeToLog([
            'bundle'   => 'eweb_ai',
            'object'   => 'temps_gagne',
            'objectId' => $this->copilot->creditSeconds('schedule_email'),
            'action'   => 'schedule_email',
            'details'  => [],
        ]);

        return new JsonResponse($base + ['done' => true]);
    }

    /**
     * Destinataires restants à l'horaire demandé. Pour un e-mail de segment,
     * le moteur ne retient que les contacts entrés dans le segment AVANT la
     * date de publication : on la pose EN MÉMOIRE le temps du calcul, puis on
     * la rétablit. Rien n'est enregistré, et le cache du moteur n'est pas
     * touché (`storeToCache` faux).
     */
    private function recipients(Email $email, \DateTimeImmutable $sendAt): ?int
    {
        $avant = $email->getPublishUp();
        try {
            $email->setPublishUp(\DateTime::createFromImmutable($sendAt));
            $n = $this->emailModel->getPendingLeads($email, null, true, null, true, null, null, false, false);

            return is_numeric($n) ? (int) $n : null;
        } catch (\Throwable) {
            return null;
        } finally {
            $email->setPublishUp($avant);
        }
    }

    private function save(Email $email): ?JsonResponse
    {
        try {
            $this->emailModel->saveEntity($email);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'save_failed'], Response::HTTP_BAD_GATEWAY);
        }

        return null;
    }

    /**
     * L'e-mail de l'écran (identifiant transmis par le panneau), sinon par son
     * NOM : exact sans casse, puis « contient » s'il n'y a qu'une
     * correspondance (même règle que la campagne simple).
     */
    private function resolve(int $id, string $ref): Email|string
    {
        if ($id > 0) {
            $email = $this->emailModel->getEntity($id);

            return $email instanceof Email ? $email : 'email_not_found';
        }
        if ('' === $ref) {
            return 'email_not_found';
        }

        $repository = $this->emailModel->getRepository();
        foreach ([['LOWER(e.name) = :nom', mb_strtolower($ref)], ['LOWER(e.name) LIKE :nom', '%'.mb_strtolower($ref).'%']] as [$where, $valeur]) {
            $trouves = $repository->createQueryBuilder('e')
                ->where($where)->setParameter('nom', $valeur)
                ->setMaxResults(2)->getQuery()->getResult();
            if (1 === count($trouves) && $trouves[0] instanceof Email) {
                return $trouves[0];
            }
            if (count($trouves) > 1) {
                return 'email_ambiguous';
            }
        }

        return 'email_not_found';
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
