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
 * ⚠️ CES DONNÉES D'ESSAI REPRODUISENT LA FORME EXACTE DU CŒUR, ET C'EST LE POINT.
 * La version précédente de ce fichier inventait un groupe `core` qui n'existe
 * nulle part, ce qui rendait INDÉTECTABLE la confusion entre la clé de groupe et
 * la propriété `object` — le défaut qui a cassé l'assistant en production. Les
 * groupes ci-dessous sont ceux que le cœur produit réellement (`lead`,
 * `behaviors`, `groups`), et la divergence groupe ≠ propriété `object` y est
 * reproduite telle quelle.
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

    // ── Le défaut de production, verrouillé ────────────────────────────────

    public function testCataloguesAFieldUnderItsGroupNotUnderItsDeclaredObject(): void
    {
        // RÉGRESSION. `FilterOperatorSubscriber` range les champs de comportement
        // dans le groupe `behaviors` alors que chacun se DÉCLARE `object: lead`.
        // C'est la clé de groupe qui fait foi : le gabarit en dérive l'identifiant
        // `#available_behaviors_...` et le filtre enregistré porte `behaviors`.
        // Indexer par la propriété rendait le champ introuvable dans la page.
        $catalog = $this->provider([
            'behaviors' => ['lead_email_read_count' => $this->choice([
                'label'      => 'E-mails lus',
                'object'     => 'lead',
                'properties' => ['type' => 'number'],
                'operators'  => ['plus grand que' => 'gt'],
            ])],
        ])->getCatalog();

        self::assertArrayHasKey('behaviors', $catalog, 'le champ doit être classé sous son GROUPE');
        self::assertArrayHasKey('lead_email_read_count', $catalog['behaviors']);
        self::assertArrayNotHasKey('lead', $catalog, 'la propriété `object` ne doit pas servir de clé');
    }

    public function testTheDigestNamesTheGroupSoTheBrowserCanFindTheOption(): void
    {
        // Le condensé alimente la proposition du modèle, qui alimente à son tour
        // `#available_<objet>_<alias>` côté navigateur. Une divergence ici casse
        // l'ajout du critère ET fait retomber le libellé sur l'alias brut.
        $digest = $this->provider([
            'behaviors' => ['lead_email_read_count' => $this->choice([
                'object'     => 'lead',
                'properties' => ['type' => 'number'],
                'operators'  => ['plus grand que' => 'gt'],
            ])],
        ])->toPromptDigest();

        self::assertStringContainsString('behaviors.lead_email_read_count|number|gt', $digest);
        self::assertStringNotContainsString('lead.lead_email_read_count', $digest);
    }

    public function testKeepsGroupsThatAreBuiltDynamically(): void
    {
        // `PointBundle` crée un groupe `groups` contenant un champ par groupe de
        // points, chacun déclaré `object: lead`. Ces champs étaient cassés de la
        // même façon, et une liste blanche d'objets en dur les exclurait.
        $catalog = $this->provider([
            'groups' => ['group_points_3' => $this->choice([
                'object'     => 'lead',
                'properties' => ['type' => 'number'],
                'operators'  => ['plus grand que' => 'gt'],
            ])],
        ])->getCatalog();

        self::assertArrayHasKey('groups', $catalog);
        self::assertArrayHasKey('group_points_3', $catalog['groups']);
    }

    // ── Les formes du cœur qui ne sont pas celles qu'on croit ──────────────

    public function testKeepsTheOperatorTokensNotTheirTranslatedLabels(): void
    {
        $catalog = $this->provider(['lead' => ['city' => $this->choice()]])->getCatalog();

        self::assertSame(['=', '!=', 'empty'], $catalog['lead']['city']['operators']);
    }

    public function testDropsOperatorsDeliberatelyOutOfScope(): void
    {
        $catalog = $this->provider([
            'lead' => ['city' => $this->choice(['operators' => [
                'égal à'     => '=',
                'expression' => 'regexp',
                'entre'      => 'between',
            ]])],
        ])->getCatalog();

        self::assertSame(['='], $catalog['lead']['city']['operators']);
    }

    public function testIgnoresAFieldLeftWithoutAnyUsableOperator(): void
    {
        $catalog = $this->provider([
            'lead' => ['weird' => $this->choice(['operators' => ['entre' => 'between']])],
        ])->getCatalog();

        self::assertSame([], $catalog);
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
        // Sans cet attribut, le cœur retire les champs statiques et TOUT le groupe
        // `behaviors` : plus de date d'ajout, de points, de tags, d'e-mails
        // ouverts. L'assistant deviendrait cosmétique — sans erreur visible.
        $request = new Request();
        $this->provider([], [], $request)->enterSegmentationContext();

        self::assertSame('loadSegmentFilterForm', $request->attributes->get('action'));
    }

    // ── Le condensé envoyé au modèle ───────────────────────────────────────

    public function testListsShortValueSetsInline(): void
    {
        $digest = $this->provider([
            'lead' => ['tags' => $this->choice([
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
            'lead' => ['campaign' => $this->choice([
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
            'lead' => ['tags' => $this->choice([
                'properties' => ['type' => 'tags', 'list' => ['1' => "A|B,C\nD"]],
            ])],
        ])->toPromptDigest();

        self::assertStringContainsString('VALUES:1=A B C D', $digest);
    }

    public function testEmitsOneDigestLinePerFieldPrefixedByItsGroup(): void
    {
        $digest = $this->provider([
            'lead'      => ['city' => $this->choice()],
            'behaviors' => ['lead_email_read_count' => $this->choice([
                'object'     => 'lead',
                'properties' => ['type' => 'number'],
                'operators'  => ['plus grand que' => 'gt'],
            ])],
        ])->toPromptDigest();

        self::assertStringContainsString('lead.city|text|=,!=,empty', $digest);
        self::assertStringContainsString('behaviors.lead_email_read_count|number|gt', $digest);
    }
}
