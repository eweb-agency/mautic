<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\DoNotContact as Dnc;
use Mautic\LeadBundle\Entity\FrequencyRule;
use Mautic\LeadBundle\Entity\FrequencyRuleRepository;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ce que la page de désabonnement lit et écrit pour un contact.
 *
 * Tout part du `Stat` de l'e-mail reçu, retrouvé par son hachage de suivi :
 * aucune méthode d'écriture ne prend d'identifiant venu de la requête.
 */
class EmailPreferences
{
    public const RHYTHM_WEEKLY  = 'weekly';

    public const RHYTHM_MONTHLY = 'monthly';

    public const PAUSE          = 'pause';

    private const PAUSE_LENGTH  = 'P3M';

    private const CHANNEL = 'email';

    /**
     * Un e-mail au plus sur la période : ce sont les seules valeurs que la
     * page propose, et celles que `FrequencyRuleRepository` sait appliquer.
     */
    private const RHYTHMS = [
        self::RHYTHM_WEEKLY  => FrequencyRule::TIME_WEEK,
        self::RHYTHM_MONTHLY => FrequencyRule::TIME_MONTH,
    ];

    public function __construct(
        private readonly EmailModel $emailModel,
        private readonly DoNotContact $doNotContact,
        private readonly EntityManagerInterface $em,
        private readonly CoreParametersHelper $parameters,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Le `Stat` du lien, seulement s'il mène à un contact : sans contact, la
     * page n'aurait rien à afficher ni à enregistrer.
     */
    public function findStat(string $idHash): ?Stat
    {
        $stat = $this->emailModel->getEmailStatus($idHash);

        return $stat instanceof Stat && $stat->getLead() instanceof Lead ? $stat : null;
    }

    /**
     * Raison de l'interdiction d'envoi (`Dnc::UNSUBSCRIBED`, `BOUNCED`,
     * `MANUAL`), ou `Dnc::IS_CONTACTABLE`.
     */
    public function dncReason(Lead $lead): int
    {
        return (int) $this->doNotContact->isContactable($lead, self::CHANNEL);
    }

    public function isRhythm(string $rhythm): bool
    {
        return isset(self::RHYTHMS[$rhythm]) || self::PAUSE === $rhythm;
    }

    /**
     * Le choix en cours parmi ceux que la page propose : la pause d'abord,
     * puis le rythme enregistré.
     */
    public function rhythm(Lead $lead): ?string
    {
        if (null !== $this->pausedUntil($lead)) {
            return self::PAUSE;
        }

        $rule = $this->findRule($lead);

        if (null === $rule || 1 !== (int) $rule->getFrequencyNumber()) {
            return null;
        }

        return array_flip(self::RHYTHMS)[$rule->getFrequencyTime()] ?? null;
    }

    /**
     * La date de reprise d'une pause en cours (celle que le moteur applique,
     * voir `FrequencyRuleRepository::getPausedContactIds`).
     */
    public function pausedUntil(Lead $lead): ?\DateTimeInterface
    {
        $rule  = $this->findRule($lead);
        $until = $rule?->getPauseToDate();
        $from  = $rule?->getPauseFromDate();
        $now   = new \DateTimeImmutable();

        return null !== $until && $until > $now && (null === $from || $from <= $now) ? $until : null;
    }

    /**
     * Écrit directement la règle du canal e-mail. `LeadModel::setFrequencyRules`
     * ne convient pas : il réécrit aussi les segments et les catégories du
     * contact à partir des données du formulaire natif, et remet les autres
     * champs de la règle à zéro.
     *
     * Une pause garde le rythme enregistré, qui reprend à son terme ; relancer
     * une pause en cours ne la prolonge pas. Choisir un rythme lève la pause.
     */
    public function saveRhythm(Lead $lead, string $rhythm): void
    {
        if (!$this->isRhythm($rhythm)) {
            throw new \InvalidArgumentException('Rythme inconnu.');
        }

        if (self::PAUSE === $rhythm && null !== $this->pausedUntil($lead)) {
            return;
        }

        $rule = $this->findRule($lead);

        if (null === $rule) {
            $rule = new FrequencyRule();
            $rule->setLead($lead);
            $rule->setChannel(self::CHANNEL);
        }

        $rule->setDateAdded(new \DateTime());

        if (self::PAUSE === $rhythm) {
            $rule->setPauseFromDate(new \DateTime());
            $rule->setPauseToDate((new \DateTime())->add(new \DateInterval(self::PAUSE_LENGTH)));
        } else {
            $rule->setFrequencyNumber(1);
            $rule->setFrequencyTime(self::RHYTHMS[$rhythm]);
            $rule->setPauseFromDate(null);
            $rule->setPauseToDate(null);
        }

        $this->rules()->saveEntity($rule);
    }

    /**
     * Même écriture que le parcours d'origine et que le clic par en-tête.
     */
    public function unsubscribe(Stat $stat): void
    {
        $this->emailModel->setDoNotContact($stat, $this->translator->trans('mautic.email.dnc.unsubscribed'), Dnc::UNSUBSCRIBED);
    }

    /**
     * Retire le seul désabonnement de ce contact. Un rebond ou un blocage posé
     * par le client n'est pas au destinataire de le lever, et un autre contact
     * de même adresse n'est pas concerné (`EmailModel::removeDoNotContact`
     * fait les deux, d'où l'appel direct).
     */
    public function resubscribe(Lead $lead): bool
    {
        if (Dnc::UNSUBSCRIBED !== $this->dncReason($lead)) {
            return false;
        }

        return $this->doNotContact->removeDncForContact($lead->getId(), self::CHANNEL, true, Dnc::UNSUBSCRIBED);
    }

    /**
     * Le nom que le destinataire connaît : celui de l'expéditeur de cet
     * e-mail, sinon celui de l'espace. `brand_name` vient en dernier car le
     * provisionneur y pose l'identifiant technique de l'espace, et
     * `mailer_from_name` vaut « Mautic » tant que rien ne l'a remplacé.
     * Le nom s'affiche quand l'organisation n'a pas de logo (voir `BrandLogo`).
     */
    public function brandName(?Email $email): string
    {
        $candidates = [
            $email?->getFromName(),
            $this->parameters->get('mailer_from_name'),
            $this->parameters->get('brand_name'),
        ];

        foreach ($candidates as $candidate) {
            $name = trim((string) $candidate);

            if ('' !== $name && !str_contains($name, '{') && 0 !== strcasecmp($name, 'mautic')) {
                return $name;
            }
        }

        return '';
    }

    /**
     * La langue du contact, sinon celle de l'espace. La page n'existe qu'en
     * français et en anglais.
     *
     * Le contact d'un `Stat` arrive sans ses champs (`getPreferredLocale()` y
     * serait toujours vide) : seul `LeadRepository::getEntity()` les charge.
     */
    public function locale(?Lead $lead): string
    {
        $contact = $lead ? $this->em->getRepository(Lead::class)->getEntity($lead->getId()) : null;

        return self::pageLocale($contact?->getPreferredLocale())
            ?? self::pageLocale(getenv('MAUTIC_DEFAULT_LOCALE') ?: $this->parameters->get('locale'))
            ?? 'en_US';
    }

    private static function pageLocale(mixed $locale): ?string
    {
        return match (strtolower(substr((string) $locale, 0, 2))) {
            'fr'    => 'fr',
            'en'    => 'en_US',
            default => null,
        };
    }

    private function findRule(Lead $lead): ?FrequencyRule
    {
        return $this->rules()->findOneBy(['lead' => $lead, 'channel' => self::CHANNEL]);
    }

    private function rules(): FrequencyRuleRepository
    {
        return $this->em->getRepository(FrequencyRule::class);
    }
}
