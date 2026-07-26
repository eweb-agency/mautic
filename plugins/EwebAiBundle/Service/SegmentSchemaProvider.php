<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

use Mautic\LeadBundle\Model\ListModel;
use Mautic\LeadBundle\Segment\RelativeDate;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Catalogue des critères de segmentation RÉELLEMENT disponibles sur CETTE
 * instance — source unique de vérité pour l'assistant de segmentation.
 *
 * POURQUOI CE SERVICE EXISTE. Le risque n°1 d'une IA qui écrit des filtres
 * n'est pas qu'elle refuse de répondre : c'est qu'elle invente un champ ou un
 * opérateur plausible. Le segment est alors vide ou faux, et le client envoie
 * sa campagne à la mauvaise audience sans le savoir. On ne « demande » donc
 * pas au modèle d'être prudent : on lui donne le vocabulaire exact de
 * l'instance, et on valide sa sortie contre ce même vocabulaire.
 *
 * Le même catalogue sert donc DEUX usages, et c'est délibéré :
 *  - `toPromptDigest()` : ce que le modèle a le droit d'employer ;
 *  - `getCatalog()` : ce contre quoi sa réponse est vérifiée.
 * Une source unique = prompt et validateur ne peuvent pas diverger.
 *
 * ⚠️ PIÈGE MAJEUR — LE CONTEXTE DE SEGMENTATION.
 * `ListModel::getChoiceFields()` déclenche un événement dont les abonnés du
 * cœur ne fournissent les champs statiques ET tout l'objet `behaviors` que si
 * `LeadListFiltersChoicesEvent::isForSegmentation()` est vrai — ce qui dépend
 * de la ROUTE courante. Depuis une route de plugin, c'est faux, et on perd
 * silencieusement date d'ajout, points, tags, campagnes, étapes, e-mails
 * envoyés/ouverts, DNC, URL visitées… L'assistant deviendrait cosmétique.
 *
 * D'où `enterSegmentationContext()`, appelée en TÊTE de chaque action du
 * contrôleur : elle pose l'attribut de requête que le cœur inspecte. C'est
 * exactement le mécanisme employé par les tests de Mautic lui-même
 * (LeadBundle/Tests/EventListener/FilterOperatorSubscriberTest).
 */
class SegmentSchemaProvider
{
    /** Objets de filtre supportés en V1 (company exclu : query-builder non audité). */
    private const SUPPORTED_OBJECTS = ['lead', 'behaviors'];

    /** Opérateurs volontairement hors périmètre (coût de fiabilisation > valeur). */
    private const EXCLUDED_OPERATORS = ['regexp', '!regexp', 'between', '!between', 'date'];

    /**
     * Au-delà de ce nombre de valeurs possibles, la liste n'est PAS envoyée au
     * modèle : il propose le champ et l'opérateur, la valeur reste à choisir
     * dans l'interface. C'est la parade aux identifiants inventés — une
     * instance peut avoir des centaines de campagnes ou de segments.
     */
    private const MAX_INLINE_VALUES = 30;

    public function __construct(
        private readonly ListModel $listModel,
        private readonly RelativeDate $relativeDate,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * À appeler AVANT toute lecture du catalogue ou tout rendu de formulaire de
     * filtres. Sans elle, le catalogue est amputé (voir le piège en tête de
     * classe) — et le rendu des lignes supprimerait les filtres en silence.
     */
    public function enterSegmentationContext(): void
    {
        $this->requestStack->getCurrentRequest()?->attributes->set(
            'action',
            'loadSegmentFilterForm'
        );
    }

    /**
     * Catalogue de validation : [objet][alias] => métadonnées du champ.
     *
     * @return array<string, array<string, array{label: string, type: string, operators: list<string>, list: array<string, string>|null}>>
     */
    public function getCatalog(): array
    {
        $catalog = [];

        foreach ($this->listModel->getChoiceFields() as $group => $choices) {
            if (!is_array($choices)) {
                continue;
            }

            foreach ($choices as $alias => $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $object = (string) ($choice['object'] ?? 'lead');
                if (!in_array($object, self::SUPPORTED_OBJECTS, true)) {
                    continue;
                }

                $properties = is_array($choice['properties'] ?? null) ? $choice['properties'] : [];
                $type       = (string) ($properties['type'] ?? 'text');

                // ⚠️ `operators` est un tableau [LIBELLÉ TRADUIT => jeton], pas
                // une liste : OperatorListTrait::getOperatorChoiceList() finit
                // par un array_flip(). Ce sont les VALEURS qu'on veut.
                $operators = array_values(
                    array_filter(
                        array_map('strval', (array) ($choice['operators'] ?? [])),
                        fn (string $op): bool => '' !== $op
                            && !in_array($op, self::EXCLUDED_OPERATORS, true)
                    )
                );

                if ([] === $operators) {
                    continue;
                }

                $list = null;
                if (isset($properties['list']) && is_array($properties['list']) && [] !== $properties['list']) {
                    $list = [];
                    foreach ($properties['list'] as $key => $label) {
                        $list[(string) $key] = is_scalar($label) ? (string) $label : (string) $key;
                    }
                }

                $catalog[$object][(string) $alias] = [
                    'label'     => (string) ($choice['label'] ?? $alias),
                    'type'      => $type,
                    'operators' => $operators,
                    'list'      => $list,
                    'group'     => (string) $group,
                ];
            }
        }

        return $catalog;
    }

    /**
     * Vocabulaire envoyé au modèle — format dense, une ligne par champ, pour
     * tenir le budget de jetons sans perdre d'information utile :
     *
     *     lead.city|text|=,!=,empty,!empty,like
     *     lead.tags|tags|in,!in|VALUES:12=VIP,7=Newsletter
     *     behaviors.lead_email_sent|leadlist|=,!=|VALUES:DEFER
     *
     * `VALUES:DEFER` signifie « trop de valeurs pour les lister : propose le
     * champ, laisse la valeur vide ». L'utilisateur complètera dans l'écran.
     */
    public function toPromptDigest(): string
    {
        $lines = [];

        foreach ($this->getCatalog() as $object => $fields) {
            foreach ($fields as $alias => $meta) {
                $line = sprintf(
                    '%s.%s|%s|%s',
                    $object,
                    $alias,
                    $meta['type'],
                    implode(',', $meta['operators'])
                );

                if (null !== $meta['list']) {
                    if (count($meta['list']) <= self::MAX_INLINE_VALUES) {
                        $pairs = [];
                        foreach ($meta['list'] as $key => $label) {
                            $pairs[] = $key.'='.str_replace(['|', ',', "\n"], ' ', $label);
                        }
                        $line .= '|VALUES:'.implode(',', $pairs);
                    } else {
                        $line .= '|VALUES:DEFER';
                    }
                }

                $lines[] = $line;
            }
        }

        sort($lines);

        return implode("\n", $lines);
    }

    /**
     * Jeton canonique de date → chaîne RELATIVE ATTENDUE PAR MAUTIC.
     *
     * ⚠️ PIÈGE MAJEUR N°2. Mautic reconnaît les dates relatives par une
     * comparaison STRICTE avec ses propres chaînes, qui sont TRADUITES dans la
     * langue de l'instance. Si le modèle écrit « last month » sur une instance
     * française, rien ne correspond : Mautic retombe sur un traitement par
     * défaut et produit un segment FAUX, sans lever la moindre erreur.
     *
     * On demande donc au modèle des jetons neutres (`month_last`), et c'est
     * cette table qui les traduit en la chaîne exacte attendue.
     *
     * @return array<string, string>
     */
    public function relativeDateMap(): array
    {
        $map = [];

        // Clés réelles vérifiées dans RelativeDate::getRelativeDateTranslationKeys() :
        // « mautic.lead.list.month_last », « mautic.lead.list.today »… Le jeton
        // canonique demandé au modèle est le dernier segment de la clé.
        foreach ($this->relativeDate->getRelativeDateStrings() as $key => $translated) {
            $token = substr((string) $key, (int) strrpos((string) $key, '.') + 1);
            if ('' !== $token && is_scalar($translated)) {
                $map[$token] = (string) $translated;
            }
        }

        return $map;
    }
}
