<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\EventSubscriber;

use Mautic\LeadBundle\Event\LeadListFiltersChoicesEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\EwebAiBundle\EventSubscriber\SegmentNeverReceivedSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « N'a jamais reçu d'e-mail » n'existe dans le cœur qu'à condition que la date
 * d'envoi accepte « vide ». Sans l'opérateur dans la LISTE NATIVE, l'assistant
 * ne peut pas poser la ligne (le formulaire refuse un opérateur inconnu), et un
 * client ne peut pas relire ce que l'assistant a fait.
 */
final class SegmentNeverReceivedSubscriberTest extends TestCase
{
    /** Opérateurs que le cœur donne à la date d'envoi : [libellé => jeton]. */
    private const CORE_OPERATORS = [
        'égal à' => '=', 'différent de' => '!=', 'après' => 'gt',
        'avant'  => 'lt', 'à partir de' => 'gte', 'jusqu au' => 'lte',
    ];

    /** @param array<string, array<string, array<string, mixed>>> $choices */
    private function event(array $choices): LeadListFiltersChoicesEvent
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnMap([
            ['mautic.lead.list.form.operator.isempty', [], null, null, 'vide'],
            ['mautic.lead.list.form.operator.isnotempty', [], null, null, 'non vide'],
        ]);

        $event = new LeadListFiltersChoicesEvent($choices, [], $translator);
        (new SegmentNeverReceivedSubscriber($translator))->addNeverReceivedOperators($event);

        return $event;
    }

    /**
     * @param array<string, string> $operators
     *
     * @return array<string, mixed>
     */
    private function sentDate(array $operators = self::CORE_OPERATORS): array
    {
        return [
            'label'      => 'E-mail envoyé (date)',
            'object'     => 'lead',
            'properties' => ['type' => 'datetime'],
            'operators'  => $operators,
        ];
    }

    public function testAddsEmptyAndNotEmptyToTheSentDate(): void
    {
        $choices = $this->event(['behaviors' => ['lead_email_sent_date' => $this->sentDate()]])->getChoices();

        $operators = $choices['behaviors']['lead_email_sent_date']['operators'];
        self::assertSame('empty', $operators['vide'] ?? null);
        self::assertSame('!empty', $operators['non vide'] ?? null);
        // Les comparaisons d'origine restent toutes là.
        foreach (self::CORE_OPERATORS as $label => $token) {
            self::assertSame($token, $operators[$label]);
        }
    }

    public function testDoesNotDuplicateAnOperatorTheCoreAlreadyOffers(): void
    {
        $choices = $this->event(['behaviors' => ['lead_email_sent_date' => $this->sentDate(
            self::CORE_OPERATORS + ['est vide' => 'empty']
        )]])->getChoices();

        $tokens = array_values($choices['behaviors']['lead_email_sent_date']['operators']);
        self::assertCount(1, array_keys($tokens, 'empty', true));
        self::assertContains('!empty', $tokens);
    }

    public function testLeavesTheReadAndClickDatesAlone(): void
    {
        // « vide » y serait FAUX (voir SegmentFormesNegativesMoteurTest) : il ne
        // doit apparaître sur aucune autre date de comportement.
        $choices = $this->event(['behaviors' => [
            'lead_email_read_date'    => $this->sentDate(),
            'email_clicked_link_date' => $this->sentDate(),
        ]])->getChoices();

        self::assertNotContains('empty', $choices['behaviors']['lead_email_read_date']['operators']);
        self::assertNotContains('empty', $choices['behaviors']['email_clicked_link_date']['operators']);
    }

    public function testDoesNothingWhenTheFieldIsAbsent(): void
    {
        // Hors contexte de segmentation, le cœur ne fournit pas `behaviors`.
        $choices = $this->event(['lead' => ['city' => ['label' => 'Ville', 'operators' => ['égal à' => '=']]]])->getChoices();

        self::assertArrayNotHasKey('behaviors', $choices);
    }

    public function testRunsAfterTheCoreCreatesTheFieldAndBeforeItNormalisesDateLabels(): void
    {
        $subscribed = SegmentNeverReceivedSubscriber::getSubscribedEvents()[LeadEvents::LIST_FILTERS_CHOICES_ON_GENERATE];

        self::assertSame('addNeverReceivedOperators', $subscribed[0]);
        self::assertLessThan(0, $subscribed[1], 'après FilterOperatorSubscriber (0)');
        self::assertGreaterThan(-1000, $subscribed[1], 'avant la normalisation des libellés de date (-1000)');
    }
}
