<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\ContactSegmentFilterCrate;
use Mautic\LeadBundle\Segment\ContactSegmentFilterFactory;
use Mautic\LeadBundle\Segment\Query\ContactSegmentQueryBuilder;
use Psr\Log\LoggerInterface;

/**
 * Barrage entre la proposition de l'IA et le segment du client.
 *
 * PRINCIPE : la sortie du modèle n'est JAMAIS de confiance. Un champ inventé,
 * un opérateur d'un autre type, une date mal formée — et le segment est vide ou
 * faux. Or un segment faux ne lève aucune erreur : il envoie simplement la
 * campagne aux mauvaises personnes. C'est le seul risque réel de cette
 * fonctionnalité, et tout ce fichier existe pour lui.
 *
 * Trois barrières successives, de la moins chère à la plus coûteuse :
 *  1. liste blanche contre le catalogue réel de l'instance (SegmentSchemaProvider) ;
 *  2. normalisation des valeurs par type (tableaux, listes, dates) ;
 *  3. essai de construction de la requête SQL — SANS L'EXÉCUTER — qui attrape
 *     ce que la liste blanche ne peut pas voir : colonne absente du schéma,
 *     opérateur non géré par le moteur, décorateur manquant.
 *
 * Rien n'est corrigé en silence : ce qui est écarté est RETOURNÉ avec sa raison,
 * pour être affiché au client (« 2 critères écartés : … »). Un assistant qui
 * jette discrètement la moitié de la demande est pire qu'un assistant qui dit
 * ce qu'il n'a pas su faire.
 */
class SegmentFilterValidator
{
    /** Au-delà, on noie l'utilisateur et on alourdit la requête pour rien. */
    private const MAX_FILTERS = 10;

    /** Bornage des listes de valeurs (opérateurs `in`). */
    private const MAX_VALUES_PER_FILTER = 50;

    /** Opérateurs qui n'attendent aucune valeur. */
    private const EMPTY_VALUE_OPERATORS = ['empty', '!empty'];

    /** Opérateurs qui exigent un TABLEAU de valeurs. */
    private const MULTI_VALUE_OPERATORS = ['in', '!in', 'in_all', '!in_all'];

    /** Types de champ traités comme des dates. */
    private const DATE_TYPES = ['date', 'datetime'];

    public function __construct(
        private readonly SegmentSchemaProvider $schema,
        private readonly ContactSegmentFilterFactory $filterFactory,
        private readonly ContactSegmentQueryBuilder $queryBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $aiFilters proposition brute du modèle
     *
     * @return array{filters: list<array<string, mixed>>, dropped: list<array{label: string, reason: string}>}
     */
    public function sanitize(array $aiFilters): array
    {
        $catalog   = $this->schema->getCatalog();
        $dateMap   = $this->schema->relativeDateMap();
        $kept      = [];
        $dropped   = [];

        foreach ($aiFilters as $raw) {
            if (count($kept) >= self::MAX_FILTERS) {
                $dropped[] = ['label' => $this->describe($raw), 'reason' => 'too_many'];
                continue;
            }

            $result = $this->sanitizeOne(is_array($raw) ? $raw : [], $catalog, $dateMap);
            if (isset($result['reason'])) {
                $dropped[] = ['label' => $this->describe($raw), 'reason' => (string) $result['reason']];
                continue;
            }

            $kept[] = $result['filter'];
        }

        // Le premier filtre porte toujours « and » : LeadList::getFilters() le
        // réécrit de toute façon à chaque lecture. Le faire ici évite d'afficher
        // au client un « ou » que le segment enregistré ne respectera pas.
        if ([] !== $kept) {
            $kept[0]['glue'] = 'and';
        }

        [$kept, $engineDropped] = $this->rejectWhatTheEngineRefuses($kept);

        return ['filters' => array_values($kept), 'dropped' => [...$dropped, ...$engineDropped]];
    }

    /**
     * @param array<string, mixed>                               $raw
     * @param array<string, array<string, array<string, mixed>>> $catalog
     * @param array<string, string>                              $dateMap
     *
     * @return array{filter?: array<string, mixed>, reason?: string}
     */
    private function sanitizeOne(array $raw, array $catalog, array $dateMap): array
    {
        $field  = isset($raw['field']) && is_scalar($raw['field']) ? (string) $raw['field'] : '';
        $object = isset($raw['object']) && is_scalar($raw['object']) ? (string) $raw['object'] : ContactSegmentFilterCrate::CONTACT_OBJECT;

        if ('' === $field) {
            return ['reason' => 'missing_field'];
        }

        // Objet inconnu, ou objet juste faux : on tente la compensation que le
        // cœur fait lui-même (un champ de `behaviors` annoncé comme `lead`).
        if (!isset($catalog[$object][$field])) {
            $found = null;
            foreach ([ContactSegmentFilterCrate::CONTACT_OBJECT, ContactSegmentFilterCrate::BEHAVIORS_OBJECT] as $candidate) {
                if (isset($catalog[$candidate][$field])) {
                    $found = $candidate;
                    break;
                }
            }
            if (null === $found) {
                return ['reason' => 'unknown_field'];
            }
            $object = $found;
        }

        $meta     = $catalog[$object][$field];
        $operator = isset($raw['operator']) && is_scalar($raw['operator']) ? (string) $raw['operator'] : '';

        if (!in_array($operator, $meta['operators'], true)) {
            return ['reason' => 'bad_operator'];
        }

        // Le TYPE vient du catalogue, jamais du modèle : ContactSegmentFilterCrate
        // caste la valeur selon lui (number → float, boolean → bool) et il pilote
        // le choix du décorateur. Un type menteur produit une requête fausse
        // SANS erreur — c'est l'un des deux pièges silencieux de ce chantier.
        $type  = (string) $meta['type'];
        $value = $raw['value'] ?? null;

        $normalized = $this->normalizeValue($value, $operator, $type, $meta, $dateMap);
        if (isset($normalized['reason'])) {
            return ['reason' => (string) $normalized['reason']];
        }

        $glue = isset($raw['glue']) && in_array($raw['glue'], ['and', 'or'], true) ? (string) $raw['glue'] : 'and';

        // Forme canonique : les valeurs vont dans `properties`, JAMAIS à la
        // racine. LeadList::addLegacyParams() régénère `filter`/`display` à la
        // lecture ; écrire les deux avec des valeurs divergentes produit un
        // segment incohérent selon le lecteur.
        // Les 5 clés sont obligatoires : le factory du cœur y accède sans `??`.
        return [
            'filter' => [
                'glue'       => $glue,
                'object'     => $object,
                'field'      => $field,
                'type'       => $type,
                'operator'   => $operator,
                'properties' => [
                    'filter'  => $normalized['value'],
                    'display' => null,
                ],
                // Métadonnée pour l'interface uniquement — retirée avant
                // enregistrement par le contrôleur.
                'needsInput' => $normalized['needsInput'] ?? false,
            ],
        ];
    }

    /**
     * @param array<string, mixed>  $meta
     * @param array<string, string> $dateMap
     *
     * @return array{value?: mixed, needsInput?: bool, reason?: string}
     */
    private function normalizeValue(
        mixed $value,
        string $operator,
        string $type,
        array $meta,
        array $dateMap,
    ): array {
        if (in_array($operator, self::EMPTY_VALUE_OPERATORS, true)) {
            // Le cœur autorise la valeur vide pour ces opérateurs uniquement.
            return ['value' => ''];
        }

        if (in_array($operator, self::MULTI_VALUE_OPERATORS, true)) {
            $list = is_array($value) ? $value : (null === $value || '' === $value ? [] : [$value]);
            if ([] === $list) {
                return ['value' => [], 'needsInput' => true];
            }
            $list = array_slice($list, 0, self::MAX_VALUES_PER_FILTER);
            $out  = [];
            foreach ($list as $item) {
                if (!is_scalar($item)) {
                    return ['reason' => 'bad_value_shape'];
                }
                $mapped = $this->mapToListKey((string) $item, $meta);
                if (null === $mapped) {
                    // Valeur non reconnue dans la liste : on garde le critère et
                    // on laisse l'utilisateur choisir, plutôt que de deviner.
                    return ['value' => [], 'needsInput' => true];
                }
                $out[] = $mapped;
            }

            return ['value' => array_values(array_unique($out))];
        }

        if (in_array($type, self::DATE_TYPES, true)) {
            return $this->normalizeDate($value, $dateMap);
        }

        if (null === $value || '' === $value) {
            return ['value' => '', 'needsInput' => true];
        }

        if (!is_scalar($value)) {
            return ['reason' => 'bad_value_shape'];
        }

        $single = $this->mapToListKey((string) $value, $meta);
        if (null === $single) {
            return ['value' => '', 'needsInput' => true];
        }

        return ['value' => $single];
    }

    /**
     * ⚠️ PIÈGE SILENCIEUX N°2 — LES DATES RELATIVES.
     *
     * Mautic reconnaît une date relative par une comparaison STRICTE avec ses
     * propres chaînes, qui sont TRADUITES dans la langue de l'instance. Une
     * valeur « last month » sur une instance française ne correspond à rien :
     * le moteur retombe sur un traitement par défaut et produit un segment FAUX
     * sans lever d'erreur.
     *
     * On accepte donc trois formes seulement, et on refuse tout le reste :
     *  - un jeton canonique (`month_last`, `today`…) → remplacé par la chaîne
     *    traduite exacte que le moteur attend ;
     *  - une date absolue `AAAA-MM-JJ` (avec heure optionnelle) ;
     *  - une expression d'intervalle anglaise que le moteur sait lire
     *    (`-30 days`, `+7 days`, `5 days ago`, `first day of this month`).
     *
     * @param array<string, string> $dateMap
     *
     * @return array{value?: mixed, needsInput?: bool, reason?: string}
     */
    private function normalizeDate(mixed $value, array $dateMap): array
    {
        if (null === $value || '' === $value) {
            return ['value' => '', 'needsInput' => true];
        }

        if (!is_scalar($value)) {
            return ['reason' => 'bad_value_shape'];
        }

        $raw = trim((string) $value);

        // 1. jeton canonique → chaîne attendue par le moteur, dans SA langue
        $token = strtolower(str_replace([' ', '-'], '_', $raw));
        if (isset($dateMap[$token])) {
            return ['value' => $dateMap[$token]];
        }

        // 2. la chaîne traduite elle-même (le modèle a pu la deviner juste)
        if (in_array($raw, $dateMap, true)) {
            return ['value' => $raw];
        }

        // 3. date absolue
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $raw)) {
            return ['value' => $raw];
        }

        // 4. expression d'intervalle reconnue par le moteur
        if (1 === preg_match('/^[+-]\s*\d+\s+\w+$/', $raw)
            || 1 === preg_match('/\bago$/i', $raw)
            || 1 === preg_match('/^(first|last) day of /i', $raw)
        ) {
            return ['value' => $raw];
        }

        return ['reason' => 'bad_date'];
    }

    /**
     * Si le champ a une liste de valeurs possibles, la valeur DOIT en être une
     * clé. Le modèle envoie souvent le libellé (« VIP ») au lieu de la clé
     * (« 12 ») : on re-mappe, sans distinction de casse. Retourne null si la
     * valeur n'appartient pas à la liste.
     *
     * @param array<string, mixed> $meta
     */
    private function mapToListKey(string $value, array $meta): ?string
    {
        $list = $meta['list'] ?? null;
        if (!is_array($list) || [] === $list) {
            return $value;
        }

        if (isset($list[$value])) {
            return $value;
        }

        foreach ($list as $key => $label) {
            if (0 === strcasecmp((string) $label, $value)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Dernière barrière : on demande au moteur de CONSTRUIRE la requête, sans
     * l'exécuter. C'est gratuit en base et cela attrape ce que la liste blanche
     * ne peut pas voir — une colonne absente du schéma réel, un opérateur que
     * le constructeur de requête ne sait pas traiter, un décorateur manquant.
     *
     * Le passage par l'ENTITÉ (setFilters puis lecture) est indispensable : il
     * régénère les clés héritées que le factory lit à la racine.
     *
     * @param list<array<string, mixed>> $filters
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array{label: string, reason: string}>}
     */
    private function rejectWhatTheEngineRefuses(array $filters): array
    {
        $kept    = [];
        $dropped = [];

        foreach ($filters as $filter) {
            $candidate = $filter;
            unset($candidate['needsInput']);

            try {
                $probe = new LeadList();
                $probe->setId(0);
                $probe->setFilters([$candidate]);

                $segmentFilters = $this->filterFactory->getSegmentFilters($probe);
                $this->queryBuilder->assembleContactsSegmentQueryBuilder(0, $segmentFilters);

                $kept[] = $filter;
            } catch (\Throwable $e) {
                $this->logger->info(
                    'EwebAiBundle: filtre de segment rejeté par le moteur ({field}) : {msg}',
                    ['field' => (string) ($filter['field'] ?? '?'), 'msg' => $e->getMessage()]
                );
                $dropped[] = [
                    'label'  => $this->describe($filter),
                    'reason' => 'engine_rejected',
                ];
            }
        }

        return [$kept, $dropped];
    }

    /** Libellé court d'un filtre, pour l'expliquer au client sans jargon. */
    private function describe(mixed $filter): string
    {
        if (!is_array($filter)) {
            return '?';
        }
        $field    = isset($filter['field']) && is_scalar($filter['field']) ? (string) $filter['field'] : '?';
        $operator = isset($filter['operator']) && is_scalar($filter['operator']) ? (string) $filter['operator'] : '';

        return trim($field.' '.$operator);
    }
}
