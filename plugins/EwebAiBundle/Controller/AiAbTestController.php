<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\EwebAiBundle\Service\AiAbTestService;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Ces objets deviennent un test A/B » — monté sur /s/ai/email/abtest.
 *
 * Contrairement aux autres endpoints IA (qui PROPOSENT sans rien toucher),
 * celui-ci CRÉE des entités : la garde est donc celle de l'écran natif
 * « abtest » — `email:emails:create` + accès à l'entité parente — et la
 * publication des variantes suit la permission de publier de l'utilisateur
 * (le pendant d'`unpublishIfLackingPermission` du contrôleur natif).
 *
 * Pas de jeton CSRF applicatif : même doctrine que les autres endpoints IA
 * (garde XHR même-origine, POST via mQuery qui porte le jeton global).
 */
final class AiAbTestController
{
    public function __construct(
        private readonly AiCopilotService $copilot,
        private readonly AiAbTestService $abTest,
        private readonly EmailModel $emailModel,
        private readonly CorePermissions $security,
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

        if (!$this->security->isGranted('email:emails:create')) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $payload  = $this->decode($request);
        $emailId  = (int) ($payload['emailId'] ?? 0);
        $subjects = is_array($payload['subjects'] ?? null) ? $payload['subjects'] : [];

        if ($emailId < 1 || [] === $subjects) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $parent = $this->emailModel->getEntity($emailId);
        if (null === $parent) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->security->hasEntityAccess('email:emails:viewown', 'email:emails:viewother', $parent->getCreatedBy())) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        // Une variante dépubliée ne reçoit aucun trafic : on publie si
        // l'utilisateur en a le droit, sinon il publiera à la main.
        $canPublish = $this->security->hasEntityAccess('email:emails:publishown', 'email:emails:publishother', $parent->getCreatedBy());

        try {
            $result = $this->abTest->createSubjectVariants($parent, $subjects, $canPublish);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse([
            'created' => array_map(
                static fn ($email): array => [
                    'id'      => $email->getId(),
                    'name'    => $email->getName(),
                    'subject' => $email->getSubject(),
                    'weight'  => (int) ($email->getVariantSettings()['weight'] ?? 0),
                ],
                $result['created']
            ),
            'skipped'   => $result['skipped'],
            'published' => $canPublish,
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
