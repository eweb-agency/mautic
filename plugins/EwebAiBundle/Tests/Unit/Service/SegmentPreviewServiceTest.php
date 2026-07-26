<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Segment\ContactSegmentService;
use MauticPlugin\EwebAiBundle\Service\SegmentPreviewService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * L'aperçu est le garde-fou MÉTIER : le seul qui attrape des critères
 * techniquement valides mais commercialement absurdes (0 contact, ou 40 000
 * au lieu de 200). Deux propriétés le rendent digne de confiance, et ces tests
 * les verrouillent :
 *
 *  - le segment sondé porte l'identifiant 0, ce qui NEUTRALISE les clauses
 *    d'ajout et de retrait manuels — sans quoi le nombre affiché ne refléterait
 *    pas les critères mais l'historique d'un autre segment ;
 *  - un critère en attente de valeur est EXCLU du calcul et signalé, jamais
 *    compté comme si sa valeur était vide.
 */
final class SegmentPreviewServiceTest extends TestCase
{
    /** @var list<LeadList> segments réellement soumis au cœur */
    private array $probes = [];

    private function service(int|string|null $count, bool $throws = false): SegmentPreviewService
    {
        $this->probes = [];

        $segments = $this->createMock(ContactSegmentService::class);
        $expects  = $segments->method('getTotalLeadListLeadsCount');

        if ($throws) {
            $expects->willThrowException(new \RuntimeException('Table does not exist'));
        } else {
            $expects->willReturnCallback(function (LeadList $probe) use ($count): array {
                $this->probes[] = $probe;

                return [$probe->getId() => ['count' => $count, 'maxId' => '99']];
            });
        }

        return new SegmentPreviewService($segments, new NullLogger());
    }

    /** @param array<string, mixed> $overrides */
    private function filter(array $overrides = []): array
    {
        return array_merge([
            'glue'       => 'and',
            'object'     => 'lead',
            'field'      => 'city',
            'type'       => 'text',
            'operator'   => '=',
            'properties' => ['filter' => 'Paris', 'display' => null],
            'needsInput' => false,
        ], $overrides);
    }

    public function testReturnsTheCountAsAnInteger(): void
    {
        // Le cœur renvoie une chaîne ; l'interface doit recevoir un nombre.
        $out = $this->service('1234')->preview([$this->filter()]);

        self::assertSame(1234, $out['count']);
        self::assertFalse($out['failed']);
        self::assertSame(0, $out['ignored']);
    }

    public function testProbesWithIdZeroSoManualMembershipCannotSkewTheCount(): void
    {
        // Avec un identifiant réel, le cœur ajouterait les contacts ajoutés à la
        // main à CE segment et retirerait ceux exclus à la main : le nombre ne
        // refléterait plus les critères proposés.
        $this->service('10')->preview([$this->filter()]);

        self::assertCount(1, $this->probes);
        self::assertSame(0, $this->probes[0]->getId());
    }

    public function testExcludesFiltersAwaitingAValueAndReportsThem(): void
    {
        $out = $this->service('42')->preview([
            $this->filter(),
            $this->filter(['field' => 'tags', 'operator' => 'in', 'needsInput' => true]),
        ]);

        self::assertSame(42, $out['count']);
        self::assertSame(1, $out['ignored'], 'le critère à compléter doit être signalé');
        self::assertCount(1, $this->probes[0]->getFilters(), 'il ne doit pas être compté');
    }

    public function testHandsTheEngineOnlyTheKeysItOwns(): void
    {
        // `needsInput` est une métadonnée d'interface. La laisser passer la
        // ferait persister dans le segment si le formulaire était enregistré tel
        // quel.
        $this->service('1')->preview([$this->filter(['explanation' => 'texte du modèle'])]);

        $submitted = $this->probes[0]->getFilters()[0];

        self::assertArrayNotHasKey('needsInput', $submitted);
        self::assertArrayNotHasKey('explanation', $submitted);
        foreach (['glue', 'object', 'field', 'type', 'operator', 'properties'] as $key) {
            self::assertArrayHasKey($key, $submitted, "clé attendue par le moteur manquante : {$key}");
        }
    }

    public function testReturnsNoCountWithoutFailingWhenNothingIsCountable(): void
    {
        // Un segment sans critère ne cible pas 0 contact : il n'est pas
        // calculable. Renvoyer 0 serait mentir.
        $out = $this->service('0')->preview([$this->filter(['needsInput' => true])]);

        self::assertNull($out['count']);
        self::assertFalse($out['failed']);
        self::assertSame(1, $out['ignored']);
        self::assertSame([], $this->probes, 'aucune requête ne doit partir');
    }

    public function testDistinguishesZeroContactsFromAnUnavailableCount(): void
    {
        // « 0 contact » est une information utile pour le client ; il ne doit pas
        // être confondu avec « je n'ai pas pu compter ».
        $out = $this->service('0')->preview([$this->filter()]);

        self::assertSame(0, $out['count']);
        self::assertFalse($out['failed']);
    }

    public function testSurvivesAnEngineFailure(): void
    {
        $out = $this->service(null, throws: true)->preview([$this->filter()]);

        self::assertNull($out['count']);
        self::assertTrue($out['failed']);
    }

    public function testFlagsAFailureWhenTheEngineReturnsNothingNumeric(): void
    {
        $out = $this->service(null)->preview([$this->filter()]);

        self::assertNull($out['count']);
        self::assertTrue($out['failed']);
    }
}
