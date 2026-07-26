<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Segment\ContactSegmentFilterFactory;
use Mautic\LeadBundle\Segment\ContactSegmentFilters;
use Mautic\LeadBundle\Segment\Query\ContactSegmentQueryBuilder;
use MauticPlugin\EwebAiBundle\Service\SegmentFilterValidator;
use MauticPlugin\EwebAiBundle\Service\SegmentSchemaProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Le validateur est la SEULE chose qui empêche un segment faux d'atteindre le
 * client. Un segment faux ne lève aucune erreur : il envoie la campagne aux
 * mauvaises personnes. Ces tests verrouillent chaque barrière, et en priorité
 * les deux pièges silencieux du moteur de segments : le type menteur et la
 * date relative non traduite.
 */
final class SegmentFilterValidatorTest extends TestCase
{
    /** Catalogue réduit mais représentatif de ce que produit l'instance. */
    private const CATALOG = [
        'lead' => [
            'city' => [
                'label'     => 'Ville', 'type' => 'text', 'list' => null,
                'operators' => ['=', '!=', 'empty', '!empty', 'like'],
            ],
            'points' => [
                'label'     => 'Points', 'type' => 'number', 'list' => null,
                'operators' => ['=', '!=', 'gt', 'gte', 'lt', 'lte'],
            ],
            'date_added' => [
                'label'     => 'Date d ajout', 'type' => 'date', 'list' => null,
                'operators' => ['=', '!=', 'gt', 'gte', 'lt', 'lte'],
            ],
            'tags' => [
                'label'     => 'Tags', 'type' => 'tags',
                'list'      => ['12' => 'VIP', '7' => 'Newsletter'],
                'operators' => ['in', '!in', 'empty', '!empty'],
            ],
        ],
        'behaviors' => [
            'lead_email_read_count' => [
                'label'     => 'E-mails lus', 'type' => 'number', 'list' => null,
                'operators' => ['=', 'gt', 'gte', 'lt', 'lte'],
            ],
        ],
    ];

    /** Chaînes relatives TRADUITES, comme les sert une instance française. */
    private const DATE_MAP = [
        'today'      => "aujourd'hui",
        'month_last' => 'le mois dernier',
        'week_this'  => 'cette semaine',
    ];

    private function validator(bool $engineAccepts = true): SegmentFilterValidator
    {
        $schema = $this->createMock(SegmentSchemaProvider::class);
        $schema->method('getCatalog')->willReturn(self::CATALOG);
        $schema->method('relativeDateMap')->willReturn(self::DATE_MAP);

        $factory = $this->createMock(ContactSegmentFilterFactory::class);
        $qb      = $this->createMock(ContactSegmentQueryBuilder::class);

        if ($engineAccepts) {
            $factory->method('getSegmentFilters')
                ->willReturn($this->createMock(ContactSegmentFilters::class));
        } else {
            $factory->method('getSegmentFilters')
                ->willThrowException(new \RuntimeException('Dunno how to handle operator'));
        }

        return new SegmentFilterValidator($schema, $factory, $qb, new NullLogger());
    }

    /** @param array<string, mixed> $overrides */
    private function filter(array $overrides = []): array
    {
        return array_merge([
            'glue'     => 'and', 'object' => 'lead', 'field' => 'city',
            'operator' => '=', 'value' => 'Paris',
        ], $overrides);
    }

    public function testAcceptsAValidFilterAndEmitsTheCanonicalShape(): void
    {
        $out = $this->validator()->sanitize([$this->filter()]);

        self::assertCount(1, $out['filters']);
        $f = $out['filters'][0];

        // Les cinq clés que le moteur lit SANS `??` doivent toutes être là.
        foreach (['glue', 'object', 'field', 'type', 'operator', 'properties'] as $key) {
            self::assertArrayHasKey($key, $f, "clé obligatoire manquante : {$key}");
        }
        // La valeur vit dans `properties`, JAMAIS à la racine.
        self::assertSame('Paris', $f['properties']['filter']);
        self::assertArrayNotHasKey('filter', $f);
        self::assertArrayNotHasKey('display', $f);
    }

    public function testRejectsAnInventedField(): void
    {
        $out = $this->validator()->sanitize([$this->filter(['field' => 'favorite_wine'])]);

        self::assertSame([], $out['filters']);
        self::assertSame('unknown_field', $out['dropped'][0]['reason']);
    }

    public function testRejectsAnOperatorThatBelongsToAnotherType(): void
    {
        // « gt » n'existe pas sur un champ texte.
        $out = $this->validator()->sanitize([$this->filter(['operator' => 'gt'])]);

        self::assertSame([], $out['filters']);
        self::assertSame('bad_operator', $out['dropped'][0]['reason']);
    }

    public function testOverwritesALyingTypeWithTheCatalogType(): void
    {
        // PIÈGE SILENCIEUX : le type pilote le cast de la valeur et le choix du
        // décorateur. Un type faux produit une requête fausse sans erreur.
        $out = $this->validator()->sanitize([
            $this->filter(['field' => 'points', 'operator' => 'gt', 'value' => 10, 'type' => 'text']),
        ]);

        self::assertSame('number', $out['filters'][0]['type']);
    }

    public function testCorrectsTheObjectWhenTheFieldBelongsToBehaviors(): void
    {
        $out = $this->validator()->sanitize([
            $this->filter(['object' => 'lead', 'field' => 'lead_email_read_count', 'operator' => 'gt', 'value' => 3]),
        ]);

        self::assertSame('behaviors', $out['filters'][0]['object']);
    }

    public function testMapsAListLabelBackToItsKey(): void
    {
        // Le modèle envoie « VIP » ; le moteur attend l'identifiant « 12 ».
        $out = $this->validator()->sanitize([
            $this->filter(['field' => 'tags', 'operator' => 'in', 'value' => ['VIP']]),
        ]);

        self::assertSame(['12'], $out['filters'][0]['properties']['filter']);
    }

    public function testWrapsASingleValueForMultiValueOperators(): void
    {
        $out = $this->validator()->sanitize([
            $this->filter(['field' => 'tags', 'operator' => 'in', 'value' => 'Newsletter']),
        ]);

        self::assertSame(['7'], $out['filters'][0]['properties']['filter']);
    }

    public function testKeepsTheFilterButAsksForInputWhenAListValueIsUnknown(): void
    {
        // Mieux vaut un critère à compléter qu'un critère deviné.
        $out = $this->validator()->sanitize([
            $this->filter(['field' => 'tags', 'operator' => 'in', 'value' => ['Inconnu']]),
        ]);

        self::assertCount(1, $out['filters']);
        self::assertTrue($out['filters'][0]['needsInput']);
    }

    public function testRejectsAnEnglishRelativeDate(): void
    {
        // PIÈGE SILENCIEUX N°2 : « last month » ne correspond à aucune chaîne
        // traduite → le moteur retomberait sur un défaut et le segment serait
        // FAUX sans erreur. On refuse.
        $out = $this->validator()->sanitize([
            $this->filter(['field' => 'date_added', 'operator' => 'gte', 'value' => 'last month']),
        ]);

        self::assertSame([], $out['filters']);
        self::assertSame('bad_date', $out['dropped'][0]['reason']);
    }

    public function testTranslatesACanonicalDateTokenIntoTheStringTheEngineExpects(): void
    {
        $out = $this->validator()->sanitize([
            $this->filter(['field' => 'date_added', 'operator' => 'gte', 'value' => 'month_last']),
        ]);

        self::assertSame('le mois dernier', $out['filters'][0]['properties']['filter']);
    }

    public function testAcceptsAnAbsoluteDate(): void
    {
        $out = $this->validator()->sanitize([
            $this->filter(['field' => 'date_added', 'operator' => 'gte', 'value' => '2026-07-01']),
        ]);

        self::assertSame('2026-07-01', $out['filters'][0]['properties']['filter']);
    }

    public function testAcceptsAnIntervalExpression(): void
    {
        $out = $this->validator()->sanitize([
            $this->filter(['field' => 'date_added', 'operator' => 'gte', 'value' => '-30 days']),
        ]);

        self::assertSame('-30 days', $out['filters'][0]['properties']['filter']);
    }

    public function testForcesTheFirstGlueToAnd(): void
    {
        // LeadList::getFilters() le réécrit de toute façon : afficher « ou » au
        // client serait mentir sur ce que le segment enregistré fera.
        $out = $this->validator()->sanitize([
            $this->filter(['glue' => 'or']),
            $this->filter(['glue' => 'or', 'field' => 'points', 'operator' => 'gt', 'value' => 5]),
        ]);

        self::assertSame('and', $out['filters'][0]['glue']);
        self::assertSame('or', $out['filters'][1]['glue']);
    }

    public function testEmptyOperatorsCarryAnEmptyValue(): void
    {
        $out = $this->validator()->sanitize([
            $this->filter(['operator' => 'empty', 'value' => 'ignoré']),
        ]);

        self::assertSame('', $out['filters'][0]['properties']['filter']);
    }

    public function testCapsTheNumberOfFilters(): void
    {
        $many = array_fill(0, 15, $this->filter());
        $out  = $this->validator()->sanitize($many);

        self::assertLessThanOrEqual(10, count($out['filters']));
        self::assertNotEmpty($out['dropped']);
    }

    public function testDropsWhatTheEngineRefusesToBuild(): void
    {
        // Dernière barrière : colonne absente, opérateur non géré, décorateur
        // manquant — invisible pour la liste blanche.
        $out = $this->validator(engineAccepts: false)->sanitize([$this->filter()]);

        self::assertSame([], $out['filters']);
        self::assertSame('engine_rejected', $out['dropped'][0]['reason']);
    }
}
