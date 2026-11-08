<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Controller\Api;

use MauticPlugin\EwebSaasBundle\Service\BrandLogo;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le portail tient cet espace à jour du logo de l'organisation.
 *
 * POST /api/saas/v1/brand/logo  {"image": "<base64>"} ou {"image": null}
 *
 * Même pare-feu OAuth2 que le reste de /api/saas/v1. Idempotent : le portail
 * le rejoue à chaque réconciliation, seul un logo différent est écrit.
 */
final class SaasBrandController
{
    public function __construct(
        private readonly BrandLogo $logo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function logoAction(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload) || !array_key_exists('image', $payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        $image = $payload['image'];

        if (null === $image) {
            return new JsonResponse(['changed' => $this->logo->remove()]);
        }

        $bytes = is_string($image) ? base64_decode($image, true) : false;

        if (false === $bytes) {
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        try {
            return new JsonResponse(['changed' => $this->logo->save($bytes)]);
        } catch (\InvalidArgumentException $exception) {
            $this->logger->warning('EwebSaasBundle: logo refusé : {msg}', ['msg' => $exception->getMessage()]);

            return new JsonResponse(['error' => 'invalid_image'], 422);
        }
    }
}
