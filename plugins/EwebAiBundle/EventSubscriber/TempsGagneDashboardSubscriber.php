<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\EventSubscriber;

use Doctrine\DBAL\Connection;
use Mautic\DashboardBundle\Event\WidgetDetailEvent;
use Mautic\DashboardBundle\EventListener\DashboardSubscriber as MainDashboardSubscriber;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;

/**
 * La TUILE « Temps rendu par l'assistant » du tableau de bord (maquette
 * validée le 28/08, emplacement n° 2) : le chiffre du mois + le total,
 * et le détail par geste en barres — la preuve quotidienne que
 * l'abonnement travaille, sans ouvrir le panneau.
 *
 * Les données viennent du journal d'audit (cf. AiCreditController) :
 * bundle eweb_ai / object temps_gagne, les secondes dans object_id et le
 * TYPE DE GESTE dans action — c'est lui qui permet le détail par geste
 * en un seul GROUP BY.
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

    /** L'ordre d'affichage des gestes (et leurs clés de libellé). */
    private const GESTES = [
        'create_landing_page' => 'mautic.eweb.ai.temps.geste.pages',
        'create_email'        => 'mautic.eweb.ai.temps.geste.emails',
        'create_segment'      => 'mautic.eweb.ai.temps.geste.segments',
        'create_form'         => 'mautic.eweb.ai.temps.geste.formulaires',
        'create_campaign'     => 'mautic.eweb.ai.temps.geste.campagnes',
        'create_report'       => 'mautic.eweb.ai.temps.geste.rapports',
        'fill_field'          => 'mautic.eweb.ai.temps.geste.champs',
    ];

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
            return ['actif' => false, 'secondsMonth' => 0, 'secondsTotal' => 0, 'gestes' => []];
        }

        $table = MAUTIC_TABLE_PREFIX.'audit_log';
        $mois  = (new \DateTimeImmutable('first day of this month midnight'))->format('Y-m-d H:i:s');

        $lignes = $this->connection->createQueryBuilder()
            ->select('a.action AS geste, COALESCE(SUM(a.object_id), 0) AS total, COALESCE(SUM(CASE WHEN a.date_added >= :mois THEN a.object_id ELSE 0 END), 0) AS mois')
            ->from($table, 'a')
            ->where('a.bundle = :bundle')
            ->andWhere('a.object = :objet')
            ->groupBy('a.action')
            ->setParameter('bundle', 'eweb_ai')
            ->setParameter('objet', 'temps_gagne')
            ->setParameter('mois', $mois)
            ->executeQuery()->fetchAllAssociative();

        $parGeste  = [];
        $totalMois = 0;
        $totalTout = 0;
        foreach ($lignes as $ligne) {
            $totalMois += (int) $ligne['mois'];
            $totalTout += (int) $ligne['total'];
            $parGeste[(string) $ligne['geste']] = (int) $ligne['mois'];
        }

        $max    = max(1, [] === $parGeste ? 1 : max($parGeste));
        $gestes = [];
        foreach (self::GESTES as $type => $cle) {
            $s = $parGeste[$type] ?? 0;
            if ($s < 60) {
                continue; // même seuil que le panneau : sous la minute, silence
            }
            $gestes[] = ['cle' => $cle, 'seconds' => $s, 'pct' => (int) round(100 * $s / $max)];
        }

        return [
            'actif'        => true,
            'secondsMonth' => $totalMois,
            'secondsTotal' => $totalTout,
            'gestes'       => $gestes,
        ];
    }
}
