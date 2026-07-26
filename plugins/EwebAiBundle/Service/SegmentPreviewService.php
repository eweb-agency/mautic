<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Service;

use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\ContactSegmentService;
use Psr\Log\LoggerInterface;

/**
 * Combien de contacts ce segment contiendrait-il — SANS l'enregistrer.
 *
 * POURQUOI C'EST LA PIÈCE QUI REND L'ASSISTANT UTILISABLE. Une proposition de
 * critères, même exacte, reste une abstraction pour un utilisateur non
 * technique : « date d'ajout > le mois dernier ET tags contient VIP » ne dit
 * rien de ce qu'on est en train de faire. Un nombre le dit. C'est aussi le seul
 * garde-fou métier contre l'erreur qu'aucune validation technique ne peut
 * attraper : des critères parfaitement valides qui ciblent 0 contact, ou 40 000
 * au lieu de 200. Le client voit le nombre AVANT d'enregistrer, et décide.
 *
 * COMMENT — le segment fantôme. On n'écrit rien en base : on construit un
 * LeadList en mémoire avec `setId(0)`, et on demande au cœur son décompte.
 * L'identifiant 0 n'est pas un bricolage, c'est ce qui rend le décompte JUSTE :
 * `getTotalLeadListLeadsCount()` ajoute deux clauses pour les contacts ajoutés
 * ou retirés À LA MAIN d'un segment existant (`lead_lists_leads.leadlist_id`).
 * Avec 0, aucune ligne ne correspond — les identifiants réels commencent à 1 —
 * donc l'inclusion manuelle n'élargit rien et l'exclusion manuelle ne rétrécit
 * rien. Le nombre obtenu reflète EXACTEMENT les critères, ce qu'on veut montrer.
 *
 * COÛT. C'est un COUNT en lecture seule, la même requête que Mautic exécute en
 * enregistrant un segment. Elle est bornée en amont : le validateur plafonne le
 * nombre de critères, et lui seul a le droit de fournir les filtres d'entrée.
 */
class SegmentPreviewService
{
    public function __construct(
        private readonly ContactSegmentService $contactSegmentService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $filters critères DÉJÀ passés par SegmentFilterValidator
     *
     * @return array{count: int|null, ignored: int, failed: bool}
     *                                                            count   : nombre de contacts, ou null si indisponible
     *                                                            ignored : critères écartés du calcul car en attente d'une valeur
     *                                                            failed  : vrai si le décompte n'a pas pu être obtenu
     */
    public function preview(array $filters): array
    {
        // Un critère « à compléter » n'a pas de valeur : le compter donnerait un
        // nombre faux, et un nombre faux est pire que pas de nombre. On l'écarte
        // et on le DIT — l'interface annonce « estimation hors N critère(s) ».
        $countable = [];
        $ignored   = 0;

        foreach ($filters as $filter) {
            if (true === ($filter['needsInput'] ?? false)) {
                ++$ignored;
                continue;
            }
            unset($filter['needsInput'], $filter['explanation'], $filter['label']);
            $countable[] = $filter;
        }

        if ([] === $countable) {
            // Aucun critère exploitable : ce n'est pas un échec, il n'y a
            // simplement rien à compter. Le cœur renverrait 0, ce qui serait
            // mensonger (un segment sans filtre ne cible pas 0 contact).
            return ['count' => null, 'ignored' => $ignored, 'failed' => false];
        }

        $probe = new LeadList();
        $probe->setId(0);
        $probe->setFilters($countable);

        try {
            $result = $this->contactSegmentService->getTotalLeadListLeadsCount($probe);
        } catch (\Throwable $e) {
            // Le validateur a déjà fait tourner l'assemblage à blanc ; si ça casse
            // ici c'est l'exécution elle-même (timeout, verrou, table absente).
            // L'assistant doit rester utilisable sans le nombre.
            $this->logger->warning('EwebAiBundle: segment preview failed: {msg}', ['msg' => $e->getMessage()]);

            return ['count' => null, 'ignored' => $ignored, 'failed' => true];
        }

        // Forme du retour : [idDuSegment => ['count' => '123', 'maxId' => '456']].
        $row   = $result[0] ?? reset($result);
        $count = is_array($row) ? ($row['count'] ?? null) : null;

        if (!is_numeric($count)) {
            $this->logger->warning('EwebAiBundle: segment preview returned no count.');

            return ['count' => null, 'ignored' => $ignored, 'failed' => true];
        }

        return ['count' => (int) $count, 'ignored' => $ignored, 'failed' => false];
    }
}
