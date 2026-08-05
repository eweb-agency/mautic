<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use MauticPlugin\EwebAiBundle\Service\SegmentFormFilterParser;
use PHPUnit\Framework\TestCase;

/**
 * Le parseur du formulaire natif — les règles qui portent le compteur :
 *  - il borne (MAX_FILTERS) et écarte les lignes que le formulaire n'a pas
 *    fini de construire, sans jamais inventer ;
 *  - il marque `needsInput` les lignes SANS valeur pour que l'aperçu les
 *    écarte EN LE DISANT — sauf les opérateurs complets sans valeur ;
 *  - il ne retouche JAMAIS les valeurs : une valeur multiple reste un tableau
 *    (piège n°5 du moteur : le séparateur est la barre verticale — joindre
 *    ici fabriquerait un segment vide que l'aperçu cautionnerait).
 */
final class SegmentFormFilterParserTest extends TestCase
{
    private SegmentFormFilterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SegmentFormFilterParser();
    }

    public function testEntreesNonExploitablesRendentUneListeVide(): void
    {
        self::assertSame([], $this->parser->parse(null));
        self::assertSame([], $this->parser->parse('pas un tableau'));
        self::assertSame([], $this->parser->parse(['scalaire', 42]));
    }

    public function testLigneSansChampOuSansOperateurEstEcartee(): void
    {
        $rows = [
            ['field' => '', 'operator' => '=', 'properties' => ['filter' => 'x']],
            ['field' => 'city', 'operator' => '', 'properties' => ['filter' => 'x']],
            ['field' => 'city', 'operator' => '=', 'properties' => ['filter' => 'Paris']],
        ];

        $filters = $this->parser->parse($rows);

        self::assertCount(1, $filters);
        self::assertSame('city', $filters[0]['field']);
    }

    public function testLaFormeMoteureEstComplete(): void
    {
        $filters = $this->parser->parse([
            [
                'glue'       => 'or',
                'field'      => 'email',
                'object'     => 'lead',
                'type'       => 'email',
                'operator'   => '!empty',
                'properties' => [],
            ],
        ]);

        self::assertSame(
            [
                'glue'       => 'or',
                'field'      => 'email',
                'object'     => 'lead',
                'type'       => 'email',
                'operator'   => '!empty',
                'properties' => [],
            ],
            $filters[0]
        );
    }

    public function testGlueInconnueRetombeSurEtEtObjetVideSurLead(): void
    {
        $filters = $this->parser->parse([
            [
                'glue'       => 'xor',
                'field'      => 'city',
                'object'     => '',
                'operator'   => '=',
                'properties' => ['filter' => 'Paris'],
            ],
        ]);

        self::assertSame('and', $filters[0]['glue']);
        self::assertSame('lead', $filters[0]['object']);
    }

    public function testValeurVideMarqueNeedsInputMaisPasLesOperateursSansValeur(): void
    {
        $filters = $this->parser->parse([
            // Valeur absente + opérateur qui en exige une → à compléter.
            ['field' => 'city', 'operator' => '=', 'properties' => ['filter' => '']],
            // Tableau de valeurs toutes vides → à compléter aussi.
            ['field' => 'tags', 'operator' => 'in', 'properties' => ['filter' => ['', ' ']]],
            // « est vide » se suffit : PAS de needsInput.
            ['field' => 'phone', 'operator' => 'empty', 'properties' => []],
            // Valeur présente : PAS de needsInput.
            ['field' => 'country', 'operator' => '=', 'properties' => ['filter' => 'France']],
        ]);

        self::assertTrue($filters[0]['needsInput'] ?? false);
        self::assertTrue($filters[1]['needsInput'] ?? false);
        self::assertArrayNotHasKey('needsInput', $filters[2]);
        self::assertArrayNotHasKey('needsInput', $filters[3]);
    }

    public function testUneValeurMultipleResteUnTableauIntact(): void
    {
        $filters = $this->parser->parse([
            [
                'field'      => 'tags',
                'operator'   => 'in',
                'properties' => ['filter' => ['VIP', 'Client']],
            ],
        ]);

        // Jamais de jointure : le moteur attend un tableau (ou « | »), une
        // chaîne « VIP, Client » serait UNE valeur littérale → segment vide.
        self::assertSame(['VIP', 'Client'], $filters[0]['properties']['filter']);
        self::assertArrayNotHasKey('needsInput', $filters[0]);
    }

    public function testLesProprietesSontTransmisesTellesQuelles(): void
    {
        $properties = ['filter' => '42', 'display' => 'Le quarante-deux'];

        $filters = $this->parser->parse([
            ['field' => 'owner_id', 'operator' => '=', 'properties' => $properties],
        ]);

        self::assertSame($properties, $filters[0]['properties']);
    }

    public function testLePlafondBorneLeNombreDeLignes(): void
    {
        $rows = array_fill(0, SegmentFormFilterParser::MAX_FILTERS + 10, [
            'field'      => 'city',
            'operator'   => '=',
            'properties' => ['filter' => 'Paris'],
        ]);

        self::assertCount(
            SegmentFormFilterParser::MAX_FILTERS,
            $this->parser->parse($rows)
        );
    }
}
