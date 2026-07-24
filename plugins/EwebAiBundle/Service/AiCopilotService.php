<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Copilote IA in-instance (façon Webmecanik) : suggestion d'objet et
 * génération / amélioration / traduction du contenu d'e-mail, via l'API
 * Anthropic.
 *
 * GATING PAR CLÉ, comme SaasWebhookNotifier : sans SENDLY_ANTHROPIC_KEY en
 * environnement, isEnabled() est faux et le service est un no-op complet —
 * aucune surface IA n'apparaît, aucun appel réseau n'est fait. La clé est lue
 * DANS le constructeur (l'autowire ne remplit pas les arguments scalaires),
 * jamais injectée via %env()% ni exposée au front.
 *
 * SÛRETÉ : le contenu d'e-mail à traiter est une DONNÉE, jamais une
 * instruction — il est toujours placé dans messages[].content, jamais
 * concaténé au system prompt (anti prompt-injection). Aucune exception ne
 * remonte à l'UI d'édition d'e-mail : en cas d'échec, on journalise (sans
 * jamais logguer la clé ni le corps) et on lève une RuntimeException au
 * message neutre, que le contrôleur convertit en JSON.
 */
class AiCopilotService
{
    private const ENDPOINT          = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_MODEL     = 'claude-haiku-4-5-20251001';

    /** Plafonds de tokens de sortie par mode. */
    private const MAX_TOKENS = [
        'subject'   => 256,
        'generate'  => 2048,
        'improve'   => 2048,
        'translate' => 2048,
    ];

    private readonly ?string $apiKey;
    private readonly string $model;

    public function __construct(
        private readonly Client $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        $this->apiKey = $this->env('SENDLY_ANTHROPIC_KEY');
        $this->model  = $this->env('SENDLY_ANTHROPIC_MODEL') ?? self::DEFAULT_MODEL;
    }

    /**
     * Vrai uniquement si une clé Anthropic est configurée. Tout le reste
     * (bouton objet, bouton éditeur, endpoint) se branche sur ce booléen.
     */
    public function isEnabled(): bool
    {
        return null !== $this->apiKey;
    }

    /**
     * Point d'entrée unique appelé par le contrôleur.
     *
     * @param array{content?: string, subject?: string, instruction?: string, lang?: string, format?: string} $params
     *
     * @throws \InvalidArgumentException mode inconnu
     * @throws \RuntimeException         échec d'appel Anthropic (message neutre)
     */
    public function generate(string $mode, array $params): string
    {
        if (!$this->isEnabled()) {
            // Ne devrait jamais arriver (le contrôleur court-circuite avant),
            // mais on reste no-op défensif.
            throw new \RuntimeException('AI copilot disabled.');
        }

        $format = 'mjml' === ($params['format'] ?? 'html') ? 'mjml' : 'html';

        [$system, $userContent] = match ($mode) {
            'subject'   => $this->buildSubjectPrompt($params),
            'generate'  => $this->buildGeneratePrompt($params, $format),
            'improve'   => $this->buildImprovePrompt($params, $format),
            'translate' => $this->buildTranslatePrompt($params, $format),
            default     => throw new \InvalidArgumentException('Unknown AI mode.'),
        };

        $text = $this->callAnthropic($system, $userContent, self::MAX_TOKENS[$mode] ?? 1024);

        return $this->stripFences(trim($text));
    }

    // ── Construction des prompts (contenu client = messages[].content) ──────

    /**
     * @param array<string, mixed> $params
     *
     * @return array{0: string, 1: string}
     */
    private function buildSubjectPrompt(array $params): array
    {
        $system = 'You are an expert email-marketing copywriter. From the body of a marketing email, propose ONE compelling subject line. '
            .'Reply with ONLY the subject line as plain text: a single line, no surrounding quotes, no preamble, no explanation, at most ~80 characters. '
            .'Write it in the same language as the email body.';

        $body    = $this->plainText((string) ($params['content'] ?? ''));
        $current = trim((string) ($params['subject'] ?? ''));

        $user = "Email body:\n".($body !== '' ? $body : '(empty)');
        if ($current !== '') {
            $user .= "\n\nCurrent subject (improve on it): ".$current;
        }

        return [$system, $user];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{0: string, 1: string}
     */
    private function buildGeneratePrompt(array $params, string $format): array
    {
        $system = 'You are an expert email-marketing copywriter and email developer. '
            .'Produce the BODY of a marketing email based on the user brief below. '
            .$this->formatRules($format)
            .' Write in the same language as the brief. Reply with ONLY the markup — no markdown code fences, no commentary.';

        $brief = trim((string) ($params['instruction'] ?? ''));
        $user  = "Brief:\n".($brief !== '' ? $brief : 'Write a short, friendly marketing email.');

        return [$system, $user];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{0: string, 1: string}
     */
    private function buildImprovePrompt(array $params, string $format): array
    {
        $system = 'You are an expert email-marketing copywriter. Improve the provided email content. '
            .'Keep the SAME output format and overall structure, and preserve every {token} / merge tag and every link exactly as-is. '
            .$this->formatRules($format)
            .' Reply with ONLY the improved markup — no markdown code fences, no commentary. Keep the original language.';

        $instruction = trim((string) ($params['instruction'] ?? ''));
        $content     = (string) ($params['content'] ?? '');

        $user = 'Instruction: '.($instruction !== '' ? $instruction : 'General improvement: clarity, persuasiveness and structure, meaning unchanged.')
            ."\n\nContent to improve:\n".$content;

        return [$system, $user];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{0: string, 1: string}
     */
    private function buildTranslatePrompt(array $params, string $format): array
    {
        $system = 'You translate email content. Preserve ALL markup and formatting and every {token} / merge tag and link exactly; translate only the human-readable text. '
            .$this->formatRules($format)
            .' Reply with ONLY the translated markup — no markdown code fences, no commentary.';

        $lang    = trim((string) ($params['lang'] ?? '')) ?: 'English';
        $content = (string) ($params['content'] ?? '');

        $user = 'Target language: '.$lang."\n\nContent to translate:\n".$content;

        return [$system, $user];
    }

    private function formatRules(string $format): string
    {
        return 'mjml' === $format
            ? 'Return a valid MJML document that starts with <mjml> and ends with </mjml>, using only standard MJML tags (mj-body, mj-section, mj-column, mj-text, mj-button, mj-image, mj-divider). Do not add comments or CDATA.'
            : 'Return an HTML email body fragment using inline CSS and table-based layout for email-client compatibility; do not wrap it in <html>, <head> or <body>.';
    }

    // ── Appel HTTP Anthropic (fail-soft) ────────────────────────────────────

    private function callAnthropic(string $system, string $userContent, int $maxTokens): string
    {
        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                RequestOptions::HEADERS => [
                    'x-api-key'         => (string) $this->apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                ],
                RequestOptions::JSON => [
                    'model'      => $this->model,
                    'max_tokens' => $maxTokens,
                    'system'     => $system,
                    'messages'   => [
                        ['role' => 'user', 'content' => $userContent],
                    ],
                ],
                RequestOptions::HTTP_ERRORS    => false,
                RequestOptions::CONNECT_TIMEOUT => 5,
                RequestOptions::TIMEOUT        => 60,
            ]);
        } catch (\Throwable $e) {
            // Réseau / transport : ne jamais faire remonter le détail à l'UI.
            $this->logger->warning('EwebAiBundle: Anthropic transport error: {msg}', ['msg' => $e->getMessage()]);
            throw new \RuntimeException('AI request failed.');
        }

        $status = $response->getStatusCode();
        $raw    = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            // On journalise le statut, jamais la clé ni le corps de la requête.
            $this->logger->warning('EwebAiBundle: Anthropic returned HTTP {status}', ['status' => $status]);
            throw new \RuntimeException('AI request failed.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('EwebAiBundle: Anthropic returned non-JSON payload.');
            throw new \RuntimeException('AI request failed.');
        }

        $text = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }

        if ('' === trim($text)) {
            $this->logger->warning('EwebAiBundle: Anthropic returned empty text content.');
            throw new \RuntimeException('AI request returned no content.');
        }

        return $text;
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Retire d'éventuelles clôtures ```lang ... ``` que le modèle aurait
     * ajoutées malgré la consigne.
     */
    private function stripFences(string $text): string
    {
        if (!str_starts_with($text, '```')) {
            return $text;
        }

        $lines = preg_split('/\R/', $text) ?: [];
        if ($lines && str_starts_with((string) $lines[0], '```')) {
            array_shift($lines);
        }
        while ($lines && '' === trim((string) end($lines))) {
            array_pop($lines);
        }
        if ($lines && '```' === trim((string) end($lines))) {
            array_pop($lines);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Réduit un corps HTML/MJML à du texte lisible pour la génération d'objet
     * et borne sa taille (les objets se décident sur les premiers mots).
     */
    private function plainText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, 4000);
    }

    private function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
