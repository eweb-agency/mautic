<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;

/**
 * Chantier C (parité Webmecanik « A/B testing IA : jusqu'à 5 objets ») :
 * transformer des suggestions d'objets en VARIANTES A/B natives de l'e-mail.
 *
 * Le geste : l'utilisateur retient plusieurs objets dans la modale de
 * suggestions ; le premier va au champ objet (côté client), les suivants
 * arrivent ici et deviennent chacun une variante native — celle que l'écran
 * « abtest » de Mautic aurait produite à la main, au champ près.
 *
 * ⚠️ LE CLONAGE D'E-MAIL EST UN CHAMP DE MINES (cartographié le 06/08, tout
 * est rejoué ici dans l'ordre du contrôleur natif `abtestAction`) :
 *  - `emailType` est ANNULÉ par __clone : non réappliqué, saveEntity force
 *    'template' et VIDE LES SEGMENTS (`setLists([])`). On le lit AVANT.
 *  - `lists` reste la MÊME instance de collection que le parent (clone
 *    superficiel PHP) : on la recopie. `excludedLists` peut rester partagée —
 *    on ne la mute jamais dans la requête, et Doctrine persiste les mêmes
 *    associations (une variante hérite des exclusions : c'est voulu).
 *  - `variantSettings` est HÉRITÉ du clone : on l'écrase toujours.
 *  - le critère de gagnant doit être IDENTIQUE sur toute la fratrie, sinon
 *    Mautic n'affiche AUCUN résultat A/B (« misconfiguration ») : s'il existe
 *    déjà des variantes, on adopte LEUR critère.
 *  - la somme des poids des ENFANTS est plafonnée à 100 (validation d'entité)
 *    et le parent reçoit le RESTE du trafic : on répartit en parts égales sur
 *    toutes les branches (parent compris) pour qu'aucune ne soit affamée.
 *  - une variante dépubliée ne reçoit AUCUN trafic : la publication est
 *    décidée par l'appelant (selon la permission de publier de l'utilisateur,
 *    comme `unpublishIfLackingPermission` côté natif).
 */
final class AiAbTestService
{
    /** Le plafond Webmecanik : 5 objets = 1 principal + 4 variantes. */
    public const MAX_VARIANTS = 4;

    private const SUBJECT_MAX_LENGTH = 500;

    public const DEFAULT_CRITERIA = 'email.openrate';

    public function __construct(
        private readonly EmailModel $emailModel,
    ) {
    }

    /**
     * @param list<string> $subjects objets candidats aux variantes (le
     *                               principal est DÉJÀ dans le formulaire,
     *                               il ne passe pas par ici)
     *
     * @return array{created: list<Email>, skipped: list<array{subject: string, reason: string}>}
     */
    public function createSubjectVariants(Email $parent, array $subjects, bool $publish): array
    {
        // Un parent qui est déjà une variante ou une traduction produirait un
        // arbre invalide (les deux hiérarchies ne se croisent pas) — même
        // refus que l'écran natif.
        if (null === $parent->getId() || $parent->getVariantParent() || $parent->getTranslationParent()) {
            throw new \InvalidArgumentException('invalid_parent');
        }

        $existing         = $parent->getVariantChildren();
        $existingSubjects = [];
        $criteria         = self::DEFAULT_CRITERIA;
        foreach ($existing as $child) {
            $existingSubjects[] = (string) $child->getSubject();
            $settings           = $child->getVariantSettings();
            if (!empty($settings['winnerCriteria'])) {
                // Fratrie existante : son critère fait loi, sinon Mautic
                // considère le test mal configuré et ne calcule plus rien.
                $criteria = (string) $settings['winnerCriteria'];
            }
        }

        $clean   = $this->sanitizeSubjects($subjects, (string) $parent->getSubject(), $existingSubjects);
        $created = [];
        $skipped = $clean['skipped'];

        if ([] === $clean['subjects']) {
            return ['created' => [], 'skipped' => $skipped];
        }

        // Répartition en parts égales sur TOUTES les branches, parent compris
        // (4 variantes à 25 affameraient l'original) — bornée par le budget
        // restant si des variantes existent déjà avec leurs propres poids.
        $branches  = count($existing) + count($clean['subjects']) + 1;
        $weight    = intdiv(100, $branches);
        $available = 100;
        foreach ($existing as $child) {
            $settings = $child->getVariantSettings();
            $available -= (int) ($settings['weight'] ?? 0);
        }
        if ($weight * count($clean['subjects']) > $available) {
            $weight = intdiv(max(0, $available), count($clean['subjects']));
        }
        if ($weight < 1) {
            throw new \InvalidArgumentException('no_traffic_budget');
        }

        // La lettre continue la fratrie : parent = A, variantes existantes
        // B..., les nôtres prennent la suite — sinon l'onglet Variantes liste
        // N lignes au nom identique.
        $letterIndex = count($existing) + 1;

        foreach ($clean['subjects'] as $subject) {
            // ⚠️ AVANT le clone : __clone met emailType à null.
            $emailType = $parent->getEmailType();

            $clone = clone $parent;
            $clone->setEmailType($emailType);
            $clone->setVariantParent($parent);
            $clone->setSubject($subject);
            $clone->setName($parent->getName().' — '.$this->letter($letterIndex));
            $clone->setLists($parent->getLists()->toArray());
            $clone->setVariantSettings([
                'weight'         => $weight,
                'winnerCriteria' => $criteria,
            ]);
            $clone->setIsPublished($publish);

            // Le cycle natif complet (variantStartDate, rattachement au
            // parent, remise à zéro des compteurs de la fratrie). Créées
            // AVANT le premier envoi, ces remises à zéro sont sans effet.
            $this->emailModel->saveEntity($clone);

            $created[] = $clone;
            ++$letterIndex;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param list<string> $subjects
     * @param list<string> $existingSubjects
     *
     * @return array{subjects: list<string>, skipped: list<array{subject: string, reason: string}>}
     */
    private function sanitizeSubjects(array $subjects, string $parentSubject, array $existingSubjects): array
    {
        $seen    = [mb_strtolower(trim($parentSubject))];
        foreach ($existingSubjects as $s) {
            $seen[] = mb_strtolower(trim($s));
        }

        $out     = [];
        $skipped = [];
        foreach ($subjects as $raw) {
            $subject = mb_substr(trim((string) $raw), 0, self::SUBJECT_MAX_LENGTH);
            if ('' === $subject) {
                continue;
            }
            $key = mb_strtolower($subject);
            if (in_array($key, $seen, true)) {
                // L'objet du parent ou d'une variante existante : une variante
                // identique fausserait le test sans rien mesurer.
                $skipped[] = ['subject' => $subject, 'reason' => 'duplicate'];
                continue;
            }
            if (count($out) >= self::MAX_VARIANTS) {
                $skipped[] = ['subject' => $subject, 'reason' => 'too_many'];
                continue;
            }
            $seen[] = $key;
            $out[]  = $subject;
        }

        return ['subjects' => $out, 'skipped' => $skipped];
    }

    /** 1 => B, 2 => C… (le parent est la branche A). */
    private function letter(int $index): string
    {
        return chr(65 + min(25, $index));
    }
}
