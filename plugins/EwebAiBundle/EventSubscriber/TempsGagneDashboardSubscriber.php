<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\EventSubscriber;

use Doctrine\DBAL\Connection;
use Mautic\DashboardBundle\Event\WidgetDetailEvent;
use Mautic\DashboardBundle\EventListener\DashboardSubscriber as MainDashboardSubscriber;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;

/**
 * La TUILE « Temps rendu par l'assistant » du tableau de bord (design
 * « rythme » validé le 28/08 après itérations en direct) : le total en
 * héros, le mois en pilule, et les 8 dernières semaines en barres — la
 * preuve quotidienne que l'abonnement travaille, sans ouvrir le panneau.
 *
 * Les données viennent du journal d'audit (cf. AiCreditController) :
 * bundle eweb_ai / object temps_gagne, les secondes dans object_id. Les
 * barres s'agrègent en PHP par semaine ISO (le volume est minuscule —
 * quelques lignes par geste exécuté — et ça reste portable MySQL/MariaDB).
 */
class TempsGagneDashboardSubscriber extends MainDashboardSubscriber
{
    /** @var string */
    protected $bundle = 'eweb_ai';

    /** @var array<string, array<string, mixed>> */
    protected $types = [
        'eweb.ai.temps.gagne' => [],
    ];

    /**
     * Pas de permission dédiée : le temps rendu par l'assistant est une
     * information d'équipe, visible de tout rôle de l'instance.
     *
     * @var array<string>
     */
    protected $permissions = [];

    /** Le nombre de semaines tracées par les barres. */
    private const SEMAINES = 8;

    public function __construct(
        private Connection $connection,
        private AiCopilotService $copilot,
    ) {
    }

    public function onWidgetDetailGenerate(WidgetDetailEvent $event): void
    {
        if ('eweb.ai.temps.gagne' !== $event->getType()) {
            return;
        }
        $this->checkPermissions($event);

        if (!$event->isCached()) {
            $event->setTemplateData($this->donnees());
        }
        $event->setTemplate('@EwebAi/SubscribedEvents/Dashboard/temps_gagne.html.twig');
        $event->stopPropagation();
    }

    /**
     * @return array<string, mixed>
     */
    private function donnees(): array
    {
        if (!$this->copilot->isEnabled()) {
            return ['actif' => false, 'secondsMonth' => 0, 'secondsTotal' => 0, 'semaines' => array_fill(0, self::SEMAINES, 0)];
        }

        $table = MAUTIC_TABLE_PREFIX.'audit_log';
        $mois  = (new \DateTimeImmutable('first day of this month midnight'))->format('Y-m-d H:i:s');

        $ligne = $this->connection->createQueryBuilder()
            ->select('COALESCE(SUM(a.object_id), 0) AS total, COALESCE(SUM(CASE WHEN a.date_added >= :mois THEN a.object_id ELSE 0 END), 0) AS mois')
            ->from($table, 'a')
            ->where('a.bundle = :bundle')
            ->andWhere('a.object = :objet')
            ->setParameter('bundle', 'eweb_ai')
            ->setParameter('objet', 'temps_gagne')
            ->setParameter('mois', $mois)
            ->executeQuery()->fetchAssociative();

        // Les barres : agrégation par semaine ISO, du lundi d'il y a
        // 7 semaines (indice 0) à la semaine en cours (dernier indice).
        // date_added est stocké en UTC → bornes calculées en UTC aussi.
        $depuis = (new \DateTimeImmutable('monday this week midnight', new \DateTimeZone('UTC')))
            ->modify('-'.(self::SEMAINES - 1).' weeks');
        $recentes = $this->connection->createQueryBuilder()
            ->select('a.date_added AS quand, a.object_id AS secondes')
            ->from($table, 'a')
            ->where('a.bundle = :bundle')
            ->andWhere('a.object = :objet')
            ->andWhere('a.date_added >= :depuis')
            ->setParameter('bundle', 'eweb_ai')
            ->setParameter('objet', 'temps_gagne')
            ->setParameter('depuis', $depuis->format('Y-m-d H:i:s'))
            ->executeQuery()->fetchAllAssociative();

        $semaines = array_fill(0, self::SEMAINES, 0);
        foreach ($recentes as $r) {
            $quand = new \DateTimeImmutable((string) $r['quand'], new \DateTimeZone('UTC'));
            $index = intdiv($quand->diff($depuis)->days, 7);
            if ($index >= 0 && $index < self::SEMAINES) {
                $semaines[$index] += (int) $r['secondes'];
            }
        }

        return [
            'actif'        => true,
            'secondsMonth' => (int) ($ligne['mois'] ?? 0),
            'secondsTotal' => (int) ($ligne['total'] ?? 0),
            'semaines'     => $semaines,
        ];
    }
}
