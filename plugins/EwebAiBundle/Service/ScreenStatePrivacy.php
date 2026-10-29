<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

/**
 * Confidentialité du relevé d'écran de l'assistant (décision proprio 29/10,
 * option A) : sur une fiche contact ou société, la VALEUR des champs ne part
 * pas au modèle. Il reçoit le nom, le libellé, et « (filled) » ou « (empty) ».
 *
 * Le navigateur applique déjà cette règle (ai-assistant.js, valeurMasquee).
 * Le serveur la REJOUE parce que c'est lui, et lui seul, qui parle au modèle :
 * un onglet resté ouvert sur l'ancien app.js (la navigation Mautic est en
 * ajax, l'app.js n'est relu qu'au rechargement complet) enverrait encore les
 * valeurs après un déploiement. Le serveur ne relit AUCUNE donnée de contact
 * en base pour l'assistant : le relevé du navigateur est la seule source.
 *
 * Règle PRUDENTE, identique des deux côtés (un test garde les listes égales) :
 * sur une fiche, toute valeur est masquée par défaut, sauf une courte liste
 * blanche de champs non personnels ; les coordonnées connues restent masquées
 * même si on les ajoutait un jour à la liste blanche. Hors fiche, le relevé
 * passe tel quel.
 */
final class ScreenStatePrivacy
{
    /** Écrans des fiches : création et édition de contact, tout l'espace sociétés. */
    public const FICHE_CHEMINS = '#/s/(?:contacts/(?:new|edit)|companies)(?:/|$)#';

    /** Champs d'un formulaire de fiche (`lead[email]`, `company[companyemail]`). */
    public const FICHE_PREFIXE = '/^(?:lead|company)\[([^\]]*)\]/';

    /** Coordonnées connues : masquées en toutes circonstances sur une fiche. */
    public const COORDONNEES = [
        'firstname', 'lastname', 'email', 'phone', 'mobile', 'fax', 'address1', 'address2',
        'city', 'zipcode', 'country', 'state', 'website', 'facebook', 'foursquare',
        'instagram', 'linkedin', 'skype', 'twitter', 'ip', 'ipaddress', 'ip_address',
        'companyname', 'companyemail', 'companyphone', 'companyfax', 'companyaddress1',
        'companyaddress2', 'companycity', 'companyzipcode', 'companycountry', 'companystate',
        'companywebsite',
    ];

    /** Liste blanche : les seuls champs d'une fiche dont la valeur part. */
    public const NON_PERSONNELS = [
        'owner', 'stage', 'tags', 'points', 'projects', 'preferred_locale',
        'preferred_profile_image', 'score', 'companyindustry',
        'companynumber_of_employees', 'companyannual_revenue',
    ];

    /**
     * Réécrit le relevé (format d'etatEcran : « Screen: », « Form fields: »,
     * une ligne `- name="…"` par champ, puis les listes `@Ln: …`) en masquant
     * les valeurs des champs de fiche. Idempotent : un relevé déjà masqué par
     * le navigateur ressort à l'identique.
     */
    public static function mask(string $context): string
    {
        if ('' === $context) {
            return '';
        }

        $lignes         = explode("\n", $context);
        $chemin         = 1 === preg_match('/^Screen: (\S*)/', $lignes[0], $m) ? $m[1] : '';
        $ficheParChemin = 1 === preg_match(self::FICHE_CHEMINS, $chemin);

        $sortie      = [];
        $refsGardees = [];
        // Vrai entre la ligne d'un champ masqué et la ligne structurée
        // suivante : les lignes de suite d'une valeur sur plusieurs lignes
        // (zone de texte) sont la valeur elle-même, elles tombent aussi.
        $dansValeurMasquee = false;

        foreach ($lignes as $ligne) {
            if (1 === preg_match('/^- name="([^"]*)"(.*)$/s', $ligne, $champ)) {
                $dansValeurMasquee = false;
                if (!self::isMasked($champ[1], $ficheParChemin)) {
                    if (1 === preg_match('/ options=@(L\d+)/', $champ[2], $ref)) {
                        $refsGardees[$ref[1]] = true;
                    }
                    $sortie[] = $ligne;
                    continue;
                }
                $sortie[]          = self::maskedLine($champ[1], $champ[2]);
                $dansValeurMasquee = true;
                continue;
            }

            if (1 === preg_match('/^@(L\d+): /', $ligne, $liste)) {
                // Une liste d'options ne part que si un champ NON masqué la
                // référence (celle d'un select ajax ne porte que l'option
                // choisie : ce serait la valeur).
                $dansValeurMasquee = false;
                if (isset($refsGardees[$liste[1]])) {
                    $sortie[] = $ligne;
                }
                continue;
            }

            if (str_starts_with($ligne, 'Screen: ') || 'Form fields:' === $ligne) {
                $dansValeurMasquee = false;
                $sortie[]          = $ligne;
                continue;
            }

            if (!$dansValeurMasquee) {
                $sortie[] = $ligne;
            }
        }

        return implode("\n", $sortie);
    }

    /**
     * Le champ porte-t-il une valeur à taire ? Sur une fiche (écran ou nom de
     * champ préfixé), oui par défaut ; seule la liste blanche y échappe.
     */
    public static function isMasked(string $name, bool $ficheParChemin): bool
    {
        $prefixe = 1 === preg_match(self::FICHE_PREFIXE, $name, $m) ? $m[1] : null;
        if (!$ficheParChemin && null === $prefixe) {
            return false;
        }

        $alias = strtolower($prefixe ?? $name);
        if (in_array($alias, self::COORDONNEES, true)) {
            return true;
        }

        return !in_array($alias, self::NON_PERSONNELS, true);
    }

    /** `- name="lead[email]" label="E-mail" value="…"` → `… label="E-mail" (filled)`. */
    private static function maskedLine(string $name, string $reste): string
    {
        $libelle = 1 === preg_match('/^ label="(.*?)"(?= options=@L\d+| value="| \((?:empty|filled)\)$|$)/s', $reste, $l)
            ? $l[1]
            : '';
        $vide = str_ends_with($reste, ' (empty)');

        return '- name="'.$name.'"'.('' !== $libelle ? ' label="'.$libelle.'"' : '').($vide ? ' (empty)' : ' (filled)');
    }
}
