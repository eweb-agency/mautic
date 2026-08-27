<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Controller;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Model\AuditLogModel;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le COMPTEUR DE TEMPS GAGNÉ (lot transverse de l'audit du 27/08) :
 * chaque geste exécuté par l'assistant crédite des secondes au barème
 * serveur — « l'assistant vous a rendu 3 h 20 ce mois-ci », l'argument
 * du plan Pro rendu mesurable.
 *
 * STOCKAGE : le journal d'audit du cœur (aucune table nouvelle) —
 * bundle 'eweb_ai', object 'temps_gagne', et les SECONDES dans object_id
 * (colonne bigint : la somme se fait en SQL). Le barème vit CÔTÉ SERVEUR
 * (creditSeconds) : le client dit ce qui s'est passé, jamais combien ça
 * vaut. Les créations d'entités (formulaire, campagne, rapport) se
 * créditent directement dans leurs contrôleurs — ici passent les gestes
 * exécutés côté écran (champs remplis, segment appliqué, sections de
 * page, corps d'e-mail).
 */
final class AiCreditController
{
    private const BUNDLE = 'eweb_ai';
    private const OBJET  = 'temps_gagne';

    public function __construct(
        private readonly AiCopilotService $copilot,
        private readonly AuditLogModel $auditLog,
        private readonly Connection $connection,
    ) {
    }

    public function creditAction(Request $request): JsonResponse
    {
        if (!$this->copilot->isEnabled()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $payload  = $this->decode($request);
        $type     = (string) ($payload['type'] ?? '');
        $quantite = (int) ($payload['quantite'] ?? 1);

        $seconds = $this->copilot->creditSeconds($type, $quantite);
        if (0 === $seconds) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $this->auditLog->writeToLog([
            'bundle'   => self::BUNDLE,
            'object'   => self::OBJET,
            'objectId' => $seconds,
            'action'   => 'credit',
            'details'  => ['type' => $type, 'quantite' => $quantite],
        ]);

        return new JsonResponse(['seconds' => $seconds]);
    }

    public function statsAction(Request $request): JsonResponse
    {
        if (!$this->copilot->isEnabled()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $table = MAUTIC_TABLE_PREFIX.'audit_log';
        $mois  = (new \DateTimeImmutable('first day of this month midnight'))->format('Y-m-d H:i:s');

        $requete = $this->connection->createQueryBuilder()
            ->select('COALESCE(SUM(a.object_id), 0) AS total, COALESCE(SUM(CASE WHEN a.date_added >= :mois THEN a.object_id ELSE 0 END), 0) AS mois')
            ->from($table, 'a')
            ->where('a.bundle = :bundle')
            ->andWhere('a.object = :objet')
            ->setParameter('bundle', self::BUNDLE)
            ->setParameter('objet', self::OBJET)
            ->setParameter('mois', $mois);
        $ligne = $requete->executeQuery()->fetchAssociative() ?: ['total' => 0, 'mois' => 0];

        return new JsonResponse([
            'seconds_month' => (int) $ligne['mois'],
            'seconds_total' => (int) $ligne['total'],
        ]);
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
