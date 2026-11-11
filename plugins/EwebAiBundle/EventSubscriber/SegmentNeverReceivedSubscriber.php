<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\EventSubscriber;

use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
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
        ];
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
