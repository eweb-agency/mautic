<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Test\Doctrine\MockedConnectionTrait;
use Mautic\LeadBundle\Provider\FilterOperatorProviderInterface;
use Mautic\LeadBundle\Segment\ContactSegmentFilter;
use Mautic\LeadBundle\Segment\ContactSegmentFilterCrate;
use Mautic\LeadBundle\Segment\ContactSegmentFilterOperator;
use Mautic\LeadBundle\Segment\Decorator\BaseDecorator;
use Mautic\LeadBundle\Segment\Decorator\CustomMappedDecorator;
use Mautic\LeadBundle\Segment\OperatorOptions;
use Mautic\LeadBundle\Segment\Query\Filter\ChannelClickQueryBuilder;
use Mautic\LeadBundle\Segment\Query\Filter\FilterQueryBuilderInterface;
use Mautic\LeadBundle\Segment\Query\Filter\ForeignFuncFilterQueryBuilder;
use Mautic\LeadBundle\Segment\Query\Filter\ForeignValueFilterQueryBuilder;
use Mautic\LeadBundle\Segment\Query\QueryBuilder;
use Mautic\LeadBundle\Segment\RandomParameterName;
use Mautic\LeadBundle\Segment\TableSchemaColumnsCache;
use Mautic\LeadBundle\Services\ContactSegmentFilterDictionary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Ce que le MOTEUR fait réellement des critères que l'assistant pose pour
 * « jamais cliqué », « jamais ouvert » et « jamais reçu ».
 *
 * Les tests du validateur prouvent la FORME des critères. Ils ne prouvent pas
 * leur SENS : un critère bien formé peut viser l'exact contraire de la demande
 * (c'est ce que faisait « A cliqué sur un lien d'un e-mail = Non » enregistré
 * avec la valeur « Non »). Ici, on fait construire la requête SQL par les vrais
 * constructeurs du cœur, sur une connexion simulée (sans base), et on lit ce
 * qu'elle sélectionne. Le décompte de l'aperçu exécute exactement ces requêtes.
 */
final class SegmentFormesNegativesMoteurTest extends TestCase
{
    use MockedConnectionTrait;

    private string $prefix = '';

    protected function setUp(): void
    {
        // Défini par l'amorce de test (config_test.php / DbPrefix) ; la CI
        // interdit de le définir dans un test.
        $this->prefix = MAUTIC_TABLE_PREFIX;
    }

    private function queryBuilder(): QueryBuilder
    {
        $connection = $this->getMockedConnection();
        $connection->method('quote')->willReturnArgument(0);

        $qb = new QueryBuilder($connection);
        $qb->select('1')->from($this->prefix.'leads', 'l');

        return $qb;
    }

    private function operator(): ContactSegmentFilterOperator
    {
        $provider = $this->createMock(FilterOperatorProviderInterface::class);
        $provider->method('getAllOperators')->willReturn(OperatorOptions::getFilterExpressionFunctions());

        return new ContactSegmentFilterOperator($provider);
    }

    private function names(): RandomParameterName
    {
        $names = $this->createMock(RandomParameterName::class);
        $names->method('generateRandomParameterName')
            ->willReturnOnConsecutiveCalls('p1', 'p2', 'p3', 'p4', 'p5');

        return $names;
    }

    /** @param array<string, mixed> $filter critère tel que l'assistant l'enregistre */
    private function sql(array $filter, string $builderClass): string
    {
        $crate = new ContactSegmentFilterCrate($filter);

        $decorator = 'email_id' === $filter['field']
            ? new BaseDecorator($this->operator())
            : new CustomMappedDecorator(
                $this->operator(),
                new ContactSegmentFilterDictionary($this->createMock(EventDispatcherInterface::class))
            );

        $schema = $this->createMock(TableSchemaColumnsCache::class);
        $schema->method('getCurrentDatabaseName')->willReturn('mautic');
        $schema->method('getColumns')->willReturn(['open_count' => true, 'date_read' => true, 'date_sent' => true]);

        $segmentFilter = new ContactSegmentFilter(
            $crate,
            $decorator,
            $schema,
            $this->createMock(FilterQueryBuilderInterface::class)
        );

        /** @var ChannelClickQueryBuilder|ForeignValueFilterQueryBuilder|ForeignFuncFilterQueryBuilder $builder */
        $builder = new $builderClass($this->names(), $this->createMock(EventDispatcherInterface::class));
        $qb      = $this->queryBuilder();
        $builder->applyQuery($qb, $segmentFilter);

        return $qb->getDebugOutput();
    }

    /** @return array<string, mixed> */
    private function filter(string $field, string $type, string $operator, mixed $value): array
    {
        return [
            'glue'       => 'and',
            'object'     => 'behaviors',
            'field'      => $field,
            'type'       => $type,
            'operator'   => $operator,
            'properties' => ['filter' => $value, 'display' => null],
        ];
    }

    // ── Jamais cliqué / déjà cliqué ───────────────────────────────────────

    public function testJamaisCliqueExclutTousLesContactsQuiOntCliqueUnLienDEMail(): void
    {
        $sql = $this->sql($this->filter('email_id', 'boolean', '=', '0'), ChannelClickQueryBuilder::class);

        self::assertSame(
            'SELECT 1 FROM '.$this->prefix.'leads l WHERE l.id NOT IN (SELECT p2.lead_id FROM '.$this->prefix.'page_hits p2 WHERE (p2.redirect_id IS NOT NULL) AND (p2.lead_id IS NOT NULL) AND (p2.source = email))',
            $sql
        );
    }

    public function testDejaCliqueGardeLesContactsQuiOntCliqueAuMoinsUneFois(): void
    {
        $sql = $this->sql($this->filter('email_id', 'boolean', '=', '1'), ChannelClickQueryBuilder::class);

        self::assertStringContainsString('l.id IN (SELECT p2.lead_id FROM '.$this->prefix.'page_hits p2', $sql);
        self::assertStringNotContainsString('NOT IN', $sql);
    }

    public function testLeLibelleNonEnregistreCommeValeurInverseLeSens(): void
    {
        // RÉGRESSION ENCADRÉE. Avant la correction de l'orientation des listes,
        // l'assistant enregistrait le LIBELLÉ « Non ». Le cœur le caste en
        // booléen VRAI : le segment visait les contacts qui ONT cliqué. Ce test
        // fixe le comportement du moteur qui rend la clé « 0 » indispensable.
        $sql = $this->sql($this->filter('email_id', 'boolean', '=', 'Non'), ChannelClickQueryBuilder::class);

        self::assertStringNotContainsString('NOT IN', $sql, 'le moteur lit « Non » comme « a cliqué »');
    }

    public function testVidePoseSurLaDateDeClicNeSeConstruitPas(): void
    {
        // Pourquoi l'assistant ne pose JAMAIS `empty` sur la date de clic : le
        // constructeur appelle expr()->empty(), qui n'existe pas.
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('empty()');

        $this->sql($this->filter('email_clicked_link_date', 'datetime', 'empty', ''), ChannelClickQueryBuilder::class);
    }

    // ── Jamais ouvert / déjà ouvert ───────────────────────────────────────

    public function testJamaisOuvertCompteZeroLectureMemeSansAucunEnvoi(): void
    {
        $qbSql = $this->sql($this->filter('lead_email_read_count', 'number', '=', '0'), ForeignFuncFilterQueryBuilder::class);

        // Une agrégation SANS GROUP BY renvoie une ligne même sur zéro envoi ;
        // COALESCE la ramène à 0 : le contact jamais destinataire compte comme
        // « jamais ouvert », ce qui est la lecture attendue.
        self::assertStringContainsString(
            'EXISTS(SELECT COALESCE(SUM(p2.open_count), 0) FROM '.$this->prefix.'email_stats p2 WHERE l.id=p2.lead_id HAVING COALESCE(SUM(p2.open_count), 0) = 0)',
            $qbSql
        );
    }

    public function testVidePoseSurLaDateDOuvertureViseEnRealiteLesJamaisDestinataires(): void
    {
        // Pourquoi l'assistant traduit « vide » sur la date d'ouverture en
        // « nombre de lectures = 0 » : le moteur ne regarde pas les lectures,
        // seulement la présence d'une ligne d'envoi. Ce serait « jamais reçu ».
        $sql = $this->sql($this->filter('lead_email_read_date', 'datetime', 'empty', ''), ForeignValueFilterQueryBuilder::class);

        self::assertSame(
            'SELECT 1 FROM '.$this->prefix.'leads l WHERE l.id NOT IN (SELECT p2.lead_id FROM '.$this->prefix.'email_stats p2)',
            $sql
        );
        self::assertStringNotContainsString('date_read', $sql);
        self::assertStringNotContainsString('is_read', $sql);
    }

    // ── Jamais reçu / déjà reçu ───────────────────────────────────────────

    public function testJamaisRecuExclutTousLesContactsAyantUneLigneDEnvoi(): void
    {
        $sql = $this->sql($this->filter('lead_email_sent_date', 'datetime', 'empty', ''), ForeignValueFilterQueryBuilder::class);

        self::assertSame(
            'SELECT 1 FROM '.$this->prefix.'leads l WHERE l.id NOT IN (SELECT p2.lead_id FROM '.$this->prefix.'email_stats p2)',
            $sql
        );
    }

    public function testDejaRecuGardeLesContactsAyantAuMoinsUneLigneDEnvoi(): void
    {
        $sql = $this->sql($this->filter('lead_email_sent_date', 'datetime', '!empty', ''), ForeignValueFilterQueryBuilder::class);

        self::assertSame(
            'SELECT 1 FROM '.$this->prefix.'leads l WHERE l.id IN (SELECT p2.lead_id FROM '.$this->prefix.'email_stats p2)',
            $sql
        );
    }
}
