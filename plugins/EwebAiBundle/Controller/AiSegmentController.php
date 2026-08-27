<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Controller;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Security\Permissions\LeadPermissions;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use MauticPlugin\EwebAiBundle\Service\SegmentFilterValidator;
use MauticPlugin\EwebAiBundle\Service\SegmentFormFilterParser;
use MauticPlugin\EwebAiBundle\Service\SegmentPreviewService;
use MauticPlugin\EwebAiBundle\Service\SegmentSchemaProvider;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Décris ta cible, je propose les critères » — monté sur /s/ai/segment/suggest.
 *
 * CHAÎNE DE RESPONSABILITÉ, dans cet ordre et pas un autre :
 *   1. contexte de segmentation posé  (sinon le catalogue est amputé)
 *   2. catalogue réel de l'instance   (le vocabulaire autorisé)
 *   3. proposition du modèle          (NON fiable par construction)
 *   4. validation stricte             (la seule barrière qui compte)
 *   5. aperçu chiffré                 (le garde-fou métier)
 *
 * Cet endpoint ne MODIFIE RIEN : il ne crée ni ne met à jour aucun segment. Il
 * propose, et c'est le formulaire natif de Mautic — donc l'utilisateur — qui
 * enregistre. Conséquence directe : aucun besoin de jeton CSRF applicatif, la
 * garde XHR même-origine suffit (identique à AiController).
 *
 * ⚠️ POURQUOI UNE PERMISSION MALGRÉ TOUT. L'endpoint ne mute rien, mais il
 * RÉVÈLE : il renvoie un décompte de contacts pour des critères arbitraires.
 * Sans garde, n'importe quel utilisateur connecté pourrait sonder la base
 * (« combien de contacts avec un e-mail chez tel concurrent ? »). La borne est
 * donc la même que pour créer ou modifier un segment.
 *
 * On ne gate PAS sur une permission `plugin`/`marketplace` : HardenRolesCommand
 * les retire aux rôles tenant à chaque démarrage, l'endpoint répondrait 403 en
 * permanence. `lead:lists:*` est une permission tenant normale.
 */
final class AiSegmentController
{
    /** Borne de la description libre (protège l'appel modèle et la facture). */
    private const MAX_DESCRIPTION = 1000;

    /**
     * Motif technique de rejet → clé de traduction montrée au client.
     *
     * Ce qui a été écarté est DIT, avec sa raison. Un assistant qui jette la
     * moitié de la demande en silence laisse croire qu'il a tout compris ; le
     * client part alors avec un segment partiel sans le savoir.
     */
    private const DROP_MESSAGES = [
        'unknown_field'    => 'mautic.lead_list.ai.dropped.unknown_field',
        'missing_field'    => 'mautic.lead_list.ai.dropped.unknown_field',
        'bad_operator'     => 'mautic.lead_list.ai.dropped.bad_operator',
        'bad_date'         => 'mautic.lead_list.ai.dropped.bad_date',
        'bad_value_shape'  => 'mautic.lead_list.ai.dropped.bad_value',
        'engine_rejected'  => 'mautic.lead_list.ai.dropped.engine_rejected',
        'too_many'         => 'mautic.lead_list.ai.dropped.too_many',
    ];

    public function __construct(
        private readonly AiCopilotService $copilot,
        private readonly SegmentSchemaProvider $schema,
        private readonly SegmentFilterValidator $validator,
        private readonly SegmentPreviewService $preview,
        private readonly SegmentFormFilterParser $formParser,
        private readonly CorePermissions $security,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function suggestAction(Request $request): JsonResponse
    {
        if (!$this->copilot->isEnabled()) {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isAllowed()) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $payload     = $this->decode($request);
        $description = trim((string) ($payload['description'] ?? ''));

        if ('' === $description) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        // ÉTAPE 1 — sans ceci, `getChoiceFields()` ne renvoie ni les champs
        // statiques ni l'objet `behaviors` : l'assistant deviendrait cosmétique.
        $this->schema->enterSegmentationContext();

        try {
            // ÉTAPE 3 — proposition brute, encore non vérifiée.
            $raw = $this->copilot->suggestSegmentFilters([
                'description' => mb_substr($description, 0, self::MAX_DESCRIPTION),
                // Contexte conversationnel : les demandes précédentes du même
                // panneau (le service borne nombre et longueur lui-même).
                'history'     => is_array($payload['history'] ?? null) ? $payload['history'] : [],
                // L'état RÉEL du formulaire : la seule vérité sur ce qui est
                // appliqué — l'utilisateur peut avoir annulé ou supprimé des
                // critères depuis les tours précédents (constat proprio 27/08 :
                // « mairies de France » après annulation ne redonnait rien).
                'current'     => is_array($payload['current'] ?? null) ? $payload['current'] : [],
                'catalog'     => $this->schema->toPromptDigest(),
                'date_tokens' => array_keys($this->schema->relativeDateMap()),
                'lang'        => $this->clientLanguage($request),
            ]);
        } catch (\Throwable) {
            // Le service a déjà journalisé la cause (sans secret ni contenu client).
            return new JsonResponse(['error' => 'ai_failed'], Response::HTTP_BAD_GATEWAY);
        }

        // ÉTAPE 4 — la barrière. Tout ce qui sort d'ici est exploitable, et rien
        // d'autre ne l'est.
        $sanitized = $this->validator->sanitize($raw['filters']);
        $filters   = $sanitized['filters'];

        // ÉTAPE 5 — le nombre. Une proposition sans nombre laisse le client
        // deviner ; c'est précisément ce qu'on veut lui éviter.
        $preview = [] === $filters
            ? ['count' => null, 'ignored' => 0, 'failed' => false]
            : $this->preview->preview($filters);

        return new JsonResponse([
            'filters' => array_map(
                // La forme envoyée au navigateur est délibérément plate : le
                // tiroir n'a pas à connaître la structure interne de Mautic.
                fn (array $f): array => [
                    'glue'       => $f['glue'],
                    'object'     => $f['object'],
                    'field'      => $f['field'],
                    'operator'   => $f['operator'],
                    'value'      => $f['properties']['filter'] ?? '',
                    'needsInput' => (bool) ($f['needsInput'] ?? false),
                ],
                $filters
            ),
            'count'   => $preview['count'],
            'ignored' => $preview['ignored'],
            'failed'  => $preview['failed'],
            'dropped' => $this->describeDropped($sanitized['dropped']),
            // Le geste complet : nom et description proposés pour les champs
            // Détails (le formulaire ne reste plus muet — constat 27/08).
            // Des VALEURS de champ, jamais du HTML : le service les a bornées.
            'name'        => $raw['name'],
            'description' => $raw['description'],
        ]);
    }

    /**
     * Le compteur EN CONTINU du formulaire de segment : combien de contacts
     * correspondent aux critères ACTUELLEMENT saisis, avant enregistrement.
     *
     * PAS de garde `isEnabled()`, délibérément : ce décompte est de la valeur
     * MOTEUR pure — aucune clé Anthropic n'intervient. Un tenant sans clé IA
     * a droit au nombre ; seule la surface IA disparaît avec la clé.
     *
     * Même exposition que `suggestAction` (l'endpoint RÉVÈLE un décompte pour
     * des critères arbitraires), donc la même permission exactement. L'entrée
     * est le formulaire natif sérialisé tel quel (`leadlist[filters]`) : les
     * champs, opérateurs et types sont déjà ceux du moteur — le parseur borne
     * et structure, il n'interprète pas.
     */
    public function countAction(Request $request): JsonResponse
    {
        if ('XMLHttpRequest' !== $request->headers->get('X-Requested-With')) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->isAllowed()) {
            return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
        }

        $leadlist = $request->request->all()['leadlist'] ?? null;
        $rows     = is_array($leadlist) && is_array($leadlist['filters'] ?? null)
            ? $leadlist['filters']
            : [];

        $filters = $this->formParser->parse($rows);

        // Aucun critère : rien à compter — pas un échec, et surtout pas un 0
        // (un segment sans filtre ne cible pas 0 contact).
        $preview = [] === $filters
            ? ['count' => null, 'ignored' => 0, 'failed' => false]
            : $this->preview->preview($filters);

        return new JsonResponse($preview);
    }

    /**
     * La borne commune des deux actions : celle de créer ou modifier un
     * segment — car les deux RÉVÈLENT des décomptes pour critères arbitraires.
     */
    private function isAllowed(): bool
    {
        return $this->security->isGranted(
            [
                LeadPermissions::LISTS_CREATE,
                LeadPermissions::LISTS_EDIT_OWN,
                LeadPermissions::LISTS_EDIT_OTHER,
            ],
            'MATCH_ONE'
        );
    }

    /**
     * Traduit les motifs de rejet côté serveur : le navigateur reçoit des
     * phrases prêtes à afficher, jamais des codes techniques à interpréter.
     *
     * @param list<array{label: string, reason: string}> $dropped
     *
     * @return list<array{label: string, message: string}>
     */
    private function describeDropped(array $dropped): array
    {
        $out = [];

        foreach ($dropped as $item) {
            $reason = (string) ($item['reason'] ?? '');
            $key    = self::DROP_MESSAGES[$reason] ?? 'mautic.lead_list.ai.dropped.generic';

            $out[] = [
                'label'   => (string) ($item['label'] ?? ''),
                'message' => $this->translator->trans($key),
            ];
        }

        return $out;
    }

    /**
     * Langue dans laquelle le modèle doit rédiger. On part de la locale de
     * l'instance : un client français ne doit pas recevoir de l'anglais.
     */
    private function clientLanguage(Request $request): string
    {
        $locale = $request->getLocale();

        return match (substr($locale, 0, 2)) {
            'fr'    => 'French',
            'es'    => 'Spanish',
            'de'    => 'German',
            'it'    => 'Italian',
            'nl'    => 'Dutch',
            'pt'    => 'Portuguese',
            default => 'English',
        };
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
