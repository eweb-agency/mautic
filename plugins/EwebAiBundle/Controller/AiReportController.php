<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\ReportBundle\Entity\Report;
use Mautic\ReportBundle\Model\ReportModel;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Les ouvertures de la semaine » — monté sur /s/ai/report/create
 * (lot 6 de l'assistant exécutant, audit du 27/08).
 *
 * Doctrine des créations (précédents : abtest, formulaires, campagnes) :
 * garde de l'écran natif (report:reports:create), la MÊME barrière de
 * validation que l'action (validateReportSpec — source en liste blanche),
 * et le rapport naît DÉPUBLIÉ. Les colonnes ne sont JAMAIS devinées :
 * elles viennent de la liste RÉELLE de la source (getColumnList), les
 * premières servies — l'utilisateur affine dans l'écran natif.
 */
final class AiReportController
{
    /** Assez pour un tableau lisible, pas assez pour un mur de colonnes. */
    private const COLONNES_MAX = 6;

    public function __construct(
        private readonly AiCopilotService $copilot,
        private readonly ReportModel $reportModel,
        private readonly CorePermissions $security,
        private readonly \Mautic\CoreBundle\Model\AuditLogModel $auditLog,
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

        if (!$this->security->isGranted('report:reports:create')) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $spec = $this->copilot->validateReportSpec($this->decode($request));
        if (null === $spec) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $colonnes = array_slice(
            array_keys((array) $this->reportModel->getColumnList($spec['source'])->choices),
            0,
            self::COLONNES_MAX
        );
        if ([] === $colonnes) {
            return new JsonResponse(['error' => 'source_unavailable'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $report = new Report();
        $report->setName($spec['name']);
        $report->setSource($spec['source']);
        $report->setColumns($colonnes);
        // DÉPUBLIÉ à la naissance : l'utilisateur affine, publie, partage.
        $report->setIsPublished(false);

        try {
            $this->reportModel->saveEntity($report);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'save_failed'], Response::HTTP_BAD_GATEWAY);
        }

        // Compteur de temps gagné : la création se crédite CÔTÉ SERVEUR
        // (barème creditSeconds, secondes dans object_id — cf AiCreditController).
        $this->auditLog->writeToLog([
            'bundle'   => 'eweb_ai',
            'object'   => 'temps_gagne',
            'objectId' => $this->copilot->creditSeconds('create_report'),
            'action'   => 'create_report',
            'details'  => [],
        ]);

        return new JsonResponse(['id' => $report->getId()]);
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
