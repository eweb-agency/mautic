<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

/**
 * Traduit les lignes du FORMULAIRE natif de segment (leadlist[filters][N])
 * vers la forme moteur que SegmentPreviewService sait compter.
 *
 * POURQUOI UN PARSEUR ET PAS LE VALIDATEUR IA. SegmentFilterValidator répare
 * la sortie d'un MODÈLE — objet rectifié, opérateur re-mappé, type imposé
 * depuis le catalogue. Ici l'entrée vient du formulaire natif de Mautic :
 * champs, opérateurs et types sont DÉJÀ ceux du moteur (c'est exactement ce
 * qui serait enregistré). Le travail est donc structurel, pas sémantique :
 * borner, écarter les lignes incomplètes, et marquer « à compléter » ce qui
 * n'a pas encore de valeur — pour que l'aperçu l'écarte ET le dise.
 *
 * ⚠️ LES VALEURS NE SONT JAMAIS RETOUCHÉES. En particulier, une valeur
 * multiple reste un TABLEAU : côté moteur le séparateur des multi-valeurs est
 * la barre verticale (piège n°5 du moteur de segments) — joindre ici avec une
 * virgule fabriquerait un segment vide que l'aperçu cautionnerait.
 */
class SegmentFormFilterParser
{
    /**
     * Même ordre de grandeur que le plafond du validateur IA : au-delà, ce
     * n'est plus un formulaire de segment, c'est une sonde.
     */
    public const MAX_FILTERS = 50;

    /** Opérateurs complets SANS valeur : « est vide » / « n'est pas vide ». */
    private const NO_VALUE_OPERATORS = ['empty', '!empty'];

    /**
     * @param mixed $rows le sous-tableau `leadlist[filters]` posté tel quel
     *
     * @return list<array<string, mixed>> filtres au format moteur ; les lignes
     *                                    sans valeur portent `needsInput` pour
     *                                    que l'aperçu les écarte en le disant
     */
    public function parse(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            if (count($out) >= self::MAX_FILTERS) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }

            $field    = trim((string) ($row['field'] ?? ''));
            $operator = trim((string) ($row['operator'] ?? ''));

            // Une ligne que le formulaire n'a pas fini de construire (champ ou
            // opérateur absents) ne peut rien cibler : on l'écarte sans bruit,
            // le natif ne l'enregistrerait pas non plus.
            if ('' === $field || '' === $operator) {
                continue;
            }

            $object     = trim((string) ($row['object'] ?? ''));
            $glue       = $row['glue'] ?? 'and';
            $properties = is_array($row['properties'] ?? null) ? $row['properties'] : [];

            $filter = [
                'glue'       => in_array($glue, ['and', 'or'], true) ? $glue : 'and',
                'field'      => $field,
                'object'     => '' !== $object ? $object : 'lead',
                'type'       => trim((string) ($row['type'] ?? '')),
                'operator'   => $operator,
                'properties' => $properties,
            ];

            if ($this->requiresValue($operator) && $this->valueIsEmpty($properties)) {
                $filter['needsInput'] = true;
            }

            $out[] = $filter;
        }

        return $out;
    }

    private function requiresValue(string $operator): bool
    {
        return !in_array($operator, self::NO_VALUE_OPERATORS, true);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function valueIsEmpty(array $properties): bool
    {
        $value = $properties['filter'] ?? null;

        if (is_array($value)) {
            return [] === array_filter($value, static fn ($item): bool => '' !== trim((string) $item));
        }

        return null === $value || '' === trim((string) $value);
    }
}
