<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\EventSubscriber;

use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\Event\SegmentDictionaryGenerationEvent;
use Mautic\LeadBundle\LeadEvents;
use Mautic\LeadBundle\Segment\OperatorOptions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Rend exprimable « n'a jamais reçu d'e-mail » (et « a déjà reçu un e-mail »).
 *
 * POURQUOI. Le cœur n'offre AUCUN critère qui dise « jamais reçu » : la date
 * d'envoi n'a que des comparaisons (=, ≠, avant, après…), et « E-mail envoyé »
 * exige de cocher chaque e-mail un par un. On ajoute donc les opérateurs
 * « vide » et « non vide » à `lead_email_sent_date`.
 *
 * POURQUOI CE CRITÈRE ET PAS UN AUTRE. Le moteur sait déjà construire ces deux
 * opérateurs pour lui, et la requête dit exactement ce qu'on veut :
 * `ForeignValueFilterQueryBuilder`, cas `empty` → contacts ABSENTS de
 * `email_stats` (aucun envoi, jamais) ; cas `notEmpty` → contacts qui y ont au
 * moins une ligne. Le même ajout serait FAUX ailleurs, et c'est pourquoi il est
 * limité à ce seul champ :
 *  - sur `lead_email_read_date`, `empty` ne filtre pas les lectures (aucune
 *    clause `is_read`) : il signifierait « jamais reçu », pas « jamais ouvert » ;
 *  - sur `email_clicked_link_date`, `ChannelClickQueryBuilder` appelle
 *    `expr()->empty()`, qui n'existe pas : la requête ne se construit pas.
 * « Jamais ouvert » et « jamais cliqué » ont leurs critères natifs justes
 * (nombre de lectures = 0, « A cliqué sur un lien d'un e-mail » = Non) : voir
 * SegmentFilterValidator::NEGATIVE_REWRITES.
 *
 * L'ajout vaut pour TOUT l'écran de segment, pas seulement pour l'assistant :
 * c'est voulu. L'assistant passe par le formulaire natif
 * (`Mautic.addLeadListFilter`), qui refuse un opérateur absent de la liste ; et
 * un client doit pouvoir relire ou refaire à la main ce que l'assistant a posé.
 *
 * Priorité -500 : après le cœur (0) qui crée le champ, avant sa normalisation
 * des libellés de date (-1000), qui ne touche pas à ces deux opérateurs.
 *
 * ⚠️ PIÈGE SILENCIEUX N°8, CONSTATÉ EN RECETTE LE 12/11/2026 : « vide » donnait
 * 0 contact sur eweb-agency, alors que 3 contacts seulement avaient reçu un
 * e-mail. `email_stats.lead_id` est NULLABLE (`addLead(true, 'SET NULL')` dans
 * EmailBundle/Entity/Stat) : supprimer un contact garde ses lignes d'envoi avec
 * un contact vide. Or le moteur écrit `l.id NOT IN (SELECT lead_id FROM
 * email_stats)`, et une seule valeur NULL dans la liste rend `NOT IN` faux pour
 * TOUS les contacts. D'où `addLeadNotNullGuard()` : la condition `where` du
 * dictionnaire, que le moteur applique à la sous-requête quel que soit
 * l'opérateur, écarte ces lignes. Sans effet sur les autres opérateurs (une
 * ligne sans contact ne désigne de toute façon personne).
 */
final class SegmentNeverReceivedSubscriber implements EventSubscriberInterface
{
    public const GROUP = 'behaviors';
    public const FIELD = 'lead_email_sent_date';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LeadEvents::LIST_FILTERS_CHOICES_ON_GENERATE => ['addNeverReceivedOperators', -500],
            LeadEvents::SEGMENT_DICTIONARY_ON_GENERATE   => ['addLeadNotNullGuard', 0],
        ];
    }

    /** Condition ajoutée à la sous-requête des envois (voir piège n°8). */
    public const LEAD_NOT_NULL = 'email_stats.lead_id IS NOT NULL';

    public function addLeadNotNullGuard(SegmentDictionaryGenerationEvent $event): void
    {
        $translations = $event->getTranslations();
        $entry        = $translations[self::FIELD] ?? null;

        // Entrée absente ou déjà conditionnée par ailleurs : on ne remplace
        // jamais une condition existante (elle pourrait être indispensable).
        if (!is_array($entry) || isset($entry['where'])) {
            return;
        }

        $entry['where'] = self::LEAD_NOT_NULL;
        $event->addTranslation(self::FIELD, $entry);
    }

    public function addNeverReceivedOperators(LeadListFiltersChoicesEvent $event): void
    {
        $choices = $event->getChoices();
        $choice  = $choices[self::GROUP][self::FIELD] ?? null;

        // Champ absent (contexte hors segmentation, ou cœur modifié) : rien à faire.
        if (!is_array($choice)) {
            return;
        }

        // ⚠️ `operators` est [LIBELLÉ TRADUIT => jeton] : on teste les VALEURS.
        $operators = is_array($choice['operators'] ?? null) ? $choice['operators'] : [];
        $added     = false;

        foreach ([
            OperatorOptions::EMPTY     => 'mautic.lead.list.form.operator.isempty',
            OperatorOptions::NOT_EMPTY => 'mautic.lead.list.form.operator.isnotempty',
        ] as $token => $labelKey) {
            if (in_array($token, $operators, true)) {
                continue;
            }
            $operators[$this->translator->trans($labelKey)] = $token;
            $added                                          = true;
        }

        if ($added) {
            $choice['operators'] = $operators;
            $event->setChoice(self::GROUP, self::FIELD, $choice);
        }
    }
}
