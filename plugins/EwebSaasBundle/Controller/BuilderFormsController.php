<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\FormBundle\Model\FormModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liste des formulaires pour la tuile « Formulaire » du builder de pages
 * (chantier D, P3a) : le sélecteur du panneau Styles la consomme en XHR.
 *
 * Aucun ajax natif ne liste les formulaires côté /s/ (FormListType est un
 * type de formulaire serveur, et l'API /saas/v1/forms est OAuth-only pour le
 * portail) — d'où cette route. Garde = celle de l'écran natif des
 * formulaires : form:forms:viewown, et la visibilité des formulaires des
 * autres suit form:forms:viewother (même filtre que FormRepository).
 */
final class BuilderFormsController
{
    public function __construct(
        private readonly CorePermissions $security,
        private readonly FormModel $formModel,
    ) {
    }

    public function listAction(Request $request): JsonResponse
    {
        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->security->isGranted('form:forms:viewown')) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $viewOther = $this->security->isGranted('form:forms:viewother');
        $forms     = $this->formModel->getRepository()->getFormList('', 100, 0, $viewOther);

        return new JsonResponse([
            'forms' => array_map(
                static fn (array $f): array => ['id' => (int) $f['id'], 'name' => (string) $f['name']],
                $forms
            ),
        ]);
    }
}
