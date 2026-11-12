<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

use Mautic\EmailBundle\Entity\Email;

/**
 * Règles de la programmation d'un envoi par Sendly Copilot (lot 2, 12/11/2026).
 *
 * POURQUOI C'EST SENSIBLE. Le moteur envoie toutes les 5 minutes
 * (`mautic:broadcasts:send`) chaque e-mail de SEGMENT publié dont la date de
 * publication est passée (`EmailRepository::getPublishedBroadcasts`).
 * Programmer, c'est donc publier avec une date : une erreur ici part chez de
 * vrais contacts. D'où une classe pure, testée seule, et une confirmation
 * explicite dans le panneau avant toute écriture (décision du propriétaire du
 * 11/11 : actions irréversibles autorisées AVEC bouton de confirmation).
 */
final class EmailScheduleRules
{
    /** Au-delà d'un an, c'est presque sûrement une erreur de saisie. */
    public const MAX_DAYS = 365;

    /** Tolérance sur « maintenant » (l'utilisateur a mis du temps à confirmer). */
    private const PAST_TOLERANCE_SECONDS = 300;

    /**
     * L'horaire demandé, au fuseau de l'instance. Vide = dès que possible
     * (le prochain passage de l'envoi planifié, sous 5 minutes).
     *
     * @return \DateTimeImmutable|string la date, ou le code d'erreur `bad_send_at`
     */
    public static function sendAt(?string $raw, \DateTimeZone $tz, \DateTimeImmutable $now): \DateTimeImmutable|string
    {
        $raw = trim((string) $raw);
        if ('' === $raw) {
            return $now->setTimezone($tz);
        }

        // Format attendu du modèle : ISO local, sans décalage (2026-11-20T09:00).
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?)?$/', $raw)) {
            return 'bad_send_at';
        }

        try {
            $date = new \DateTimeImmutable($raw, $tz);
        } catch (\Throwable) {
            return 'bad_send_at';
        }

        if ($date < $now->modify('-'.self::PAST_TOLERANCE_SECONDS.' seconds')) {
            return 'bad_send_at';
        }
        if ($date > $now->modify('+'.self::MAX_DAYS.' days')) {
            return 'bad_send_at';
        }

        return $date;
    }

    /**
     * Pourquoi cet e-mail ne peut pas être programmé, ou null s'il le peut.
     *
     * - seuls les e-mails de SEGMENT partent par l'envoi planifié (un e-mail
     *   « modèle » part par une campagne ou un formulaire) ;
     * - sans segment destinataire, publier n'enverrait rien à personne ;
     * - une variante A/B suit son parent : c'est le parent qu'on programme ;
     * - une date de fin de publication antérieure à l'envoi l'empêcherait.
     */
    public static function refus(Email $email, \DateTimeImmutable $sendAt): ?string
    {
        if ('list' !== $email->getEmailType()) {
            return 'not_segment_email';
        }
        if (0 === count($email->getLists())) {
            return 'no_segment';
        }
        if (null !== $email->getVariantParent()) {
            return 'is_variant';
        }
        $publishDown = $email->getPublishDown();
        if ($publishDown instanceof \DateTimeInterface && $publishDown <= $sendAt) {
            return 'publish_down_before';
        }

        return null;
    }

    /** Libellé français de l'horaire, pour le bouton de confirmation. */
    public static function libelle(\DateTimeImmutable $date, \DateTimeImmutable $now): string
    {
        if ($date <= $now->modify('+'.self::PAST_TOLERANCE_SECONDS.' seconds')) {
            return 'dès maintenant (sous 5 minutes)';
        }

        return 'le '.$date->format('d/m/Y').' à '.$date->format('H:i');
    }
}
