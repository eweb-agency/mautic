<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Controller;

use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint AJAX du copilote IA, monté sur /s/ai/generate (préfixe /s ajouté
 * par le RouteLoader → firewall session : seul un admin connecté l'atteint).
 *
 * `final class` sans extends (comme SaasStatsController) : simple couche de
 * transport JSON, pas besoin du contexte de sécurité de CommonController. Le
 * gating par clé est fait AVANT tout appel réseau : sans clé, l'endpoint
 * existe mais répond 503 inerte.
 *
 * On ne gate PAS sur une permission plugin/marketplace (HardenRolesCommand
 * les retire aux tenants à chaque boot → 403) : l'auth de session du firewall
 * /s suffit.
 */
final class AiController
{
    /** Bornes de taille des entrées (protège l'appel LLM et la facture). */
    private const MAX_CONTENT     = 60000;
    private const MAX_INSTRUCTION = 4000;
    private const MAX_SUBJECT     = 500;

    private const MODES = ['subject', 'generate', 'improve', 'translate'];

    public function __construct(
        private readonly AiCopilotService $copilot,
    ) {
    }

    public function generateAction(Request $request): JsonResponse
    {
        // Court-circuit : aucune clé → aucune fonctionnalité, aucun appel réseau.
        if (!$this->copilot->isEnabled()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Garde anti-CSRF légère : l'appel doit être un XHR même-origine. Une
        // requête cross-site simple ne peut pas poser cet en-tête sans
        // pré-vol, et l'endpoint ne mute rien — cela suffit à décourager
        // l'abus de la clé via une page tierce.
        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $payload = $this->decode($request);
        $mode    = (string) ($payload['mode'] ?? '');

        if (!in_array($mode, self::MODES, true)) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Objet : N propositions (façon Webmecanik). Contenu : un seul bloc.
            if ('subject' === $mode) {
                $suggestions = $this->copilot->suggestSubjects([
                    'content'      => mb_substr((string) ($payload['content'] ?? ''), 0, self::MAX_CONTENT),
                    'subject'      => mb_substr((string) ($payload['subject'] ?? ''), 0, self::MAX_SUBJECT),
                    'tone'         => mb_substr((string) ($payload['tone'] ?? ''), 0, 40),
                    'emojis'       => filter_var($payload['emojis'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'instructions' => mb_substr((string) ($payload['instructions'] ?? ''), 0, self::MAX_INSTRUCTION),
                    'lang'         => mb_substr((string) ($payload['lang'] ?? ''), 0, 60),
                    'count'        => (int) ($payload['count'] ?? 3),
                ]);

                return new JsonResponse(['suggestions' => $suggestions]);
            }

            $text = $this->copilot->generate($mode, [
                'content'     => mb_substr((string) ($payload['content'] ?? ''), 0, self::MAX_CONTENT),
                'instruction' => mb_substr((string) ($payload['instruction'] ?? ''), 0, self::MAX_INSTRUCTION),
                'lang'        => mb_substr((string) ($payload['lang'] ?? ''), 0, 60),
                'format'      => 'mjml' === ($payload['format'] ?? '') ? 'mjml' : 'html',
            ]);

            return new JsonResponse(['text' => $text]);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            // Le service a déjà journalisé la cause réelle (sans secret).
            return new JsonResponse(['error' => 'ai_failed'], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * L'assistant d'aide — panneau « Assistant IA » de la barre haute.
     *
     * Mêmes gardes que generateAction (clé, XHR même-origine), aucune
     * permission métier : l'aide s'adresse à tout utilisateur connecté et ne
     * révèle aucune donnée du compte — le service ne reçoit que la question
     * et l'historique de la conversation, jamais le contenu de l'instance.
     */
    public function assistAction(Request $request): JsonResponse
    {
        if (!$this->copilot->isEnabled()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        $payload = $this->decode($request);

        try {
            $answer = $this->copilot->assist([
                'question' => mb_substr((string) ($payload['question'] ?? ''), 0, self::MAX_INSTRUCTION),
                'history'  => $payload['history'] ?? [],
                'lang'     => mb_substr((string) ($payload['lang'] ?? ''), 0, 60),
            ]);

            return new JsonResponse(['answer' => $answer]);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            // Le service a déjà journalisé la cause réelle (sans secret).
            return new JsonResponse(['error' => 'ai_failed'], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Décode le corps JSON, avec repli sur les paramètres POST classiques.
     *
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
