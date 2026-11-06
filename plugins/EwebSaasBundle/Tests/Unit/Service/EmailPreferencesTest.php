<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\DoNotContact as Dnc;
use Mautic\LeadBundle\Entity\FrequencyRule;
use Mautic\LeadBundle\Entity\FrequencyRuleRepository;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\EwebSaasBundle\Service\EmailPreferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EmailPreferencesTest extends TestCase
{
    private EmailModel&MockObject $emailModel;

    private DoNotContact&MockObject $doNotContact;

    private FrequencyRuleRepository&MockObject $rules;

    private LeadRepository&MockObject $contacts;

    private CoreParametersHelper&MockObject $parameters;

    private EmailPreferences $preferences;

    protected function setUp(): void
    {
        $this->emailModel   = $this->createMock(EmailModel::class);
        $this->doNotContact = $this->createMock(DoNotContact::class);
        $this->rules        = $this->createMock(FrequencyRuleRepository::class);
        $this->parameters   = $this->createMock(CoreParametersHelper::class);

        $this->contacts     = $this->createMock(LeadRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [FrequencyRule::class, $this->rules],
            [Lead::class, $this->contacts],
        ]);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $this->preferences = new EmailPreferences($this->emailModel, $this->doNotContact, $em, $this->parameters, $translator);
    }

    public function testUnLienSansStatNeDonneRien(): void
    {
        $this->emailModel->method('getEmailStatus')->willReturn(null);

        self::assertNull($this->preferences->findStat('inconnu'));
    }

    public function testUnStatSansContactNeDonneRien(): void
    {
        $this->emailModel->method('getEmailStatus')->willReturn(new Stat());

        self::assertNull($this->preferences->findStat('sans-contact'));
    }

    public function testUnStatAvecContactEstRetrouveParSonHachage(): void
    {
        $stat = new Stat();
        $stat->setLead(new Lead());
        $this->emailModel->method('getEmailStatus')->with('hachage')->willReturn($stat);

        self::assertSame($stat, $this->preferences->findStat('hachage'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rythmes(): iterable
    {
        yield 'semaine' => [EmailPreferences::RHYTHM_WEEKLY, FrequencyRule::TIME_WEEK];
        yield 'mois' => [EmailPreferences::RHYTHM_MONTHLY, FrequencyRule::TIME_MONTH];
    }

    #[DataProvider('rythmes')]
    public function testUnNouveauRythmeCreeLaRegleDuCanalEmail(string $rhythm, string $time): void
    {
        $lead = new Lead();
        $this->rules->method('findOneBy')->willReturn(null);

        $saved = null;
        $this->rules->expects(self::once())->method('saveEntity')->willReturnCallback(function (FrequencyRule $rule) use (&$saved): void {
            $saved = $rule;
        });

        $this->preferences->saveRhythm($lead, $rhythm);

        // Ce sont les colonnes que lit FrequencyRuleRepository à l'envoi.
        self::assertInstanceOf(FrequencyRule::class, $saved);
        self::assertSame($lead, $saved->getLead());
        self::assertSame('email', $saved->getChannel());
        self::assertSame(1, $saved->getFrequencyNumber());
        self::assertSame($time, $saved->getFrequencyTime());
        self::assertInstanceOf(\DateTimeInterface::class, $saved->getDateAdded());
    }

    public function testChangerDeRythmeMetAJourLaRegleExistanteSansToucherAuReste(): void
    {
        $pause = new \DateTime('2030-01-01');
        $rule  = new FrequencyRule();
        $rule->setChannel('email');
        $rule->setPreferredChannel(true);
        $rule->setPauseFromDate($pause);
        $rule->setFrequencyNumber(1);
        $rule->setFrequencyTime(FrequencyRule::TIME_WEEK);

        $this->rules->method('findOneBy')->willReturn($rule);
        $this->rules->expects(self::once())->method('saveEntity')->with($rule);

        $this->preferences->saveRhythm(new Lead(), EmailPreferences::RHYTHM_MONTHLY);

        self::assertSame(FrequencyRule::TIME_MONTH, $rule->getFrequencyTime());
        self::assertTrue((bool) $rule->getPreferredChannel());
        self::assertSame($pause, $rule->getPauseFromDate());
    }

    public function testUnRythmeInconnuEstRefuse(): void
    {
        $this->rules->expects(self::never())->method('saveEntity');

        $this->expectException(\InvalidArgumentException::class);

        $this->preferences->saveRhythm(new Lead(), 'pause');
    }

    public function testLaPageNeProposePasLaPause(): void
    {
        // Aucun envoi ne lit pause_from_date ni pause_to_date dans ce moteur :
        // proposer la pause serait une fausse promesse.
        self::assertTrue($this->preferences->isRhythm('weekly'));
        self::assertTrue($this->preferences->isRhythm('monthly'));
        self::assertFalse($this->preferences->isRhythm('pause'));
    }

    #[DataProvider('rythmes')]
    public function testLeRythmeEnregistreEstRelu(string $rhythm, string $time): void
    {
        $rule = new FrequencyRule();
        $rule->setFrequencyNumber(1);
        $rule->setFrequencyTime($time);
        $this->rules->method('findOneBy')->willReturn($rule);

        self::assertSame($rhythm, $this->preferences->rhythm(new Lead()));
    }

    public function testUneRegleQueLaPageNeSaitPasAfficherNEstPasUnRythme(): void
    {
        $rule = new FrequencyRule();
        $rule->setFrequencyNumber(3);
        $rule->setFrequencyTime(FrequencyRule::TIME_WEEK);
        $this->rules->method('findOneBy')->willReturn($rule);

        self::assertNull($this->preferences->rhythm(new Lead()));
    }

    public function testSansRegleIlNYAPasDeRythme(): void
    {
        $this->rules->method('findOneBy')->willReturn(null);

        self::assertNull($this->preferences->rhythm(new Lead()));
    }

    public function testSeDesabonnerEcritCommeLeParcoursDOrigine(): void
    {
        $stat = new Stat();

        $this->emailModel->expects(self::once())->method('setDoNotContact')
            ->with($stat, 'mautic.email.dnc.unsubscribed', Dnc::UNSUBSCRIBED);

        $this->preferences->unsubscribe($stat);
    }

    public function testSeReabonnerRetireLeDesabonnementDeCeSeulContact(): void
    {
        $lead = $this->lead(42);
        $this->doNotContact->method('isContactable')->with($lead, 'email')->willReturn(Dnc::UNSUBSCRIBED);

        $this->doNotContact->expects(self::once())->method('removeDncForContact')
            ->with(42, 'email', true, Dnc::UNSUBSCRIBED)->willReturn(true);
        $this->emailModel->expects(self::never())->method('removeDoNotContact');

        self::assertTrue($this->preferences->resubscribe($lead));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function blocagesQuiNeSontPasAuDestinataire(): iterable
    {
        yield 'rebond' => [Dnc::BOUNCED];
        yield 'blocage manuel' => [Dnc::MANUAL];
        yield 'déjà contactable' => [Dnc::IS_CONTACTABLE];
    }

    #[DataProvider('blocagesQuiNeSontPasAuDestinataire')]
    public function testUnBlocageQuiNEstPasUnDesabonnementResteEnPlace(int $reason): void
    {
        $this->doNotContact->method('isContactable')->willReturn($reason);
        $this->doNotContact->expects(self::never())->method('removeDncForContact');

        self::assertFalse($this->preferences->resubscribe($this->lead(42)));
    }

    /**
     * @return iterable<string, array{?string, mixed, mixed, string}>
     */
    public static function marques(): iterable
    {
        yield 'expéditeur de l\'e-mail' => ['Atelier Mistral', 'Nom du compte', 'atelier', 'Atelier Mistral'];
        yield 'expéditeur de l\'espace' => [null, 'Nom du compte', 'atelier', 'Nom du compte'];
        yield 'nom de l\'espace' => [null, '', 'atelier', 'atelier'];
        yield 'jeton dans l\'expéditeur' => ['{contactfield=owner}', 'Nom du compte', 'atelier', 'Nom du compte'];
        yield 'valeur par défaut du moteur' => [null, 'Mautic', 'atelier', 'atelier'];
        yield 'rien' => [null, 'MAUTIC', '', ''];
    }

    #[DataProvider('marques')]
    public function testLaMarqueAfficheeEstCelleQueLeDestinataireConnait(?string $fromName, mixed $mailerName, mixed $brandName, string $expected): void
    {
        $email = new Email();
        $email->setFromName($fromName);

        $this->parameters->method('get')->willReturnMap([
            ['mailer_from_name', null, $mailerName],
            ['brand_name', null, $brandName],
        ]);

        self::assertSame($expected, $this->preferences->brandName($email));
    }

    /**
     * @return iterable<string, array{?string, string, string}>
     */
    public static function langues(): iterable
    {
        yield 'contact français' => ['fr', 'en_US', 'fr'];
        yield 'contact français de Belgique' => ['fr_BE', 'en_US', 'fr'];
        yield 'contact anglais sur un espace français' => ['en_GB', 'fr', 'en_US'];
        yield 'contact sans langue, espace français' => [null, 'fr', 'fr'];
        yield 'contact allemand, espace français' => ['de', 'fr', 'fr'];
        yield 'aucune langue de la page' => ['de', 'it', 'en_US'];
    }

    #[DataProvider('langues')]
    public function testLaLangueEstCelleDuContactSinonCelleDeLEspace(?string $contact, string $space, string $expected): void
    {
        $this->parameters->method('get')->with('locale')->willReturn($space);

        // Le contact d'un Stat est vide : la langue n'est que sur celui de la base.
        $loaded = new Lead();
        $loaded->addUpdatedField('preferred_locale', $contact);
        $this->contacts->method('getEntity')->with(42)->willReturn($loaded);

        $previous = getenv('MAUTIC_DEFAULT_LOCALE');
        putenv('MAUTIC_DEFAULT_LOCALE');

        try {
            self::assertSame($expected, $this->preferences->locale($this->lead(42)));
        } finally {
            false === $previous ? putenv('MAUTIC_DEFAULT_LOCALE') : putenv('MAUTIC_DEFAULT_LOCALE='.$previous);
        }
    }

    public function testSansContactLaLangueEstCelleDeLEspace(): void
    {
        // Un lien invalide ne mène à personne : l'écran suit l'espace.
        $this->parameters->method('get')->with('locale')->willReturn('fr');
        $this->contacts->expects(self::never())->method('getEntity');

        $previous = getenv('MAUTIC_DEFAULT_LOCALE');
        putenv('MAUTIC_DEFAULT_LOCALE');

        try {
            self::assertSame('fr', $this->preferences->locale(null));
        } finally {
            false === $previous ? putenv('MAUTIC_DEFAULT_LOCALE') : putenv('MAUTIC_DEFAULT_LOCALE='.$previous);
        }
    }

    private function lead(int $id): Lead&MockObject
    {
        $lead = $this->createMock(Lead::class);
        $lead->method('getId')->willReturn($id);

        return $lead;
    }
}
