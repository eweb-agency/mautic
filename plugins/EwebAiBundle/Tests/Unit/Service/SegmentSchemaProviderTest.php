<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use Mautic\LeadBundle\Model\ListModel;
use Mautic\LeadBundle\Segment\RelativeDate;
use MauticPlugin\EwebAiBundle\Service\SegmentSchemaProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Le catalogue est la source unique : ce que le modèle a le droit d'employer,
 * ET ce contre quoi sa réponse est vérifiée. Une erreur ici se propage donc aux
 * deux côtés à la fois, et reste invisible — le modèle proposerait des critères
 * cohérents avec un vocabulaire faux.
 *
 * Ces tests verrouillent en priorité les deux formes du cœur qui ne sont pas
 * celles qu'on croit :
 *
 *  - `operators` est un tableau [LIBELLÉ TRADUIT => jeton] et non une liste,
 *    parce que le cœur termine par un array_flip(). Prendre les clés donnerait
 *    au modèle des libellés français en guise d'opérateurs ;
 *  - les jetons de date sont le DERNIER segment de clés de traduction du type
 *    « mautic.lead.list.month_last ».
 */
final class SegmentSchemaProviderTest extends TestCase
{
    private function provider(array $choiceFields, array $dateStrings = [], ?Request $request = null): SegmentSchemaProvider
    {
        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getChoiceFields')->willReturn($choiceFields);

        $relative = $this->createMock(RelativeDate::class);
        $relative->method('getRelativeDateStrings')->willReturn($dateStrings);

        $stack = new RequestStack();
        if (null !== $request) {
            $stack->push($request);
        }

        return new SegmentSchemaProvider($listModel, $relative, $stack);
    }

    /** Forme exacte d'une entrée telle que la produit le cœur. */
    private function choice(array $overrides = []): array
    {
        return array_merge([
            'label'      => 'Ville',
            'object'     => 'lead',
            'properties' => ['type' => 'text'],
            // [libellé traduit => jeton] — la forme piégeuse.
            'operators'  => ['égal à' => '=', 'différent de' => '!=', 'est vide' => 'empty'],
        ], $overrides);
    }

    public function testKeepsTheOperatorTokensNotTheirTranslatedLabels(): void
    {
        $catalog = $this->provider(['core' => ['city' => $this->choice()]])->getCatalog();

        self::assertSame(['=', '!=', 'empty'], $catalog['lead']['city']['operators']);
    }

    public function testDropsOperatorsDeliberatelyOutOfScope(): void
    {
        $catalog = $this->provider([
            'core' => ['city' => $this->choice(['operators' => [
                'égal à'      => '=',
                'expression'  => 'regexp',
                'entre'       => 'between',
            ]])],
        ])->getCatalog();

        self::assertSame(['='], $catalog['lead']['city']['operators']);
    }

    public function testIgnoresObjectsThatAreNotSupported(): void
    {
        // `company` a son propre constructeur de requête, non audité : le
        // proposer produirait des segments dont on ne garantit rien.
        $catalog = $this->provider([
            'core' => [
                'city'      => $this->choice(),
                'companyname' => $this->choice(['object' => 'company']),
            ],
        ])->getCatalog();

        self::assertArrayHasKey('city', $catalog['lead']);
        self::assertArrayNotHasKey('company', $catalog);
    }

    public function testIgnoresAFieldLeftWithoutAnyUsableOperator(): void
    {
        $catalog = $this->provider([
            'core' => ['weird' => $this->choice(['operators' => ['entre' => 'between']])],
        ])->getCatalog();

        self::assertSame([], $catalog);
    }

    public function testListsShortValueSetsInline(): void
    {
        $digest = $this->provider([
            'core' => ['tags' => $this->choice([
                'label'      => 'Tags',
                'properties' => ['type' => 'tags', 'list' => ['12' => 'VIP', '7' => 'Newsletter']],
            ])],
        ])->toPromptDigest();

        self::assertStringContainsString('VALUES:12=VIP,7=Newsletter', $digest);
    }

    public function testDefersValueSetsTooLongToEnumerate(): void
    {
        // Au-delà du seuil, on n'envoie pas la liste : le modèle propose le champ
        // et l'opérateur, la valeur est choisie dans l'interface. C'est la parade
        // aux identifiants inventés sur une instance à 300 campagnes.
        $long = [];
        for ($i = 1; $i <= 40; ++$i) {
            $long[(string) $i] = 'Campagne '.$i;
        }

        $digest = $this->provider([
            'core' => ['campaign' => $this->choice([
                'properties' => ['type' => 'leadlist', 'list' => $long],
            ])],
        ])->toPromptDigest();

        self::assertStringContainsString('VALUES:DEFER', $digest);
        self::assertStringNotContainsString('Campagne 7', $digest);
    }

    public function testNeutralisesSeparatorsInsideLabelsSoTheDigestStaysParsable(): void
    {
        // Le condensé est délimité par | et , : un libellé client qui en contient
        // décalerait la lecture de toute la ligne.
        $digest = $this->provider([
            'core' => ['tags' => $this->choice([
                'properties' => ['type' => 'tags', 'list' => ['1' => "A|B,C\nD"]],
            ])],
        ])->toPromptDigest();

        self::assertStringContainsString('VALUES:1=A B C D', $digest);
    }

    public function testTurnsTranslationKeysIntoNeutralDateTokens(): void
    {
        $map = $this->provider([], [
            'mautic.lead.list.month_last' => 'le mois dernier',
            'mautic.lead.list.today'      => "aujourd'hui",
        ])->relativeDateMap();

        self::assertSame(['month_last' => 'le mois dernier', 'today' => "aujourd'hui"], $map);
    }

    public function testEntersTheSegmentationContextTheCoreInspects(): void
    {
        // Sans cet attribut, le cœur retire les champs statiques et TOUT l'objet
        // `behaviors` : plus de date d'ajout, de points, de tags, d'e-mails
        // ouverts. L'assistant deviendrait cosmétique — sans erreur visible.
        $request = new Request();
        $this->provider([], [], $request)->enterSegmentationContext();

        self::assertSame('loadSegmentFilterForm', $request->attributes->get('action'));
    }

    public function testEmitsOneDigestLinePerFieldPrefixedByItsObject(): void
    {
        $digest = $this->provider([
            'core'      => ['city' => $this->choice()],
            'behaviors' => ['lead_email_read_count' => $this->choice([
                'object'     => 'behaviors',
                'properties' => ['type' => 'number'],
                'operators'  => ['plus grand que' => 'gt'],
            ])],
        ])->toPromptDigest();

        self::assertStringContainsString('lead.city|text|=,!=,empty', $digest);
        self::assertStringContainsString('behaviors.lead_email_read_count|number|gt', $digest);
    }
}
