<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use Mautic\EmailBundle\Entity\Email;
use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\EwebAiBundle\Service\EmailScheduleRules;
use PHPUnit\Framework\TestCase;

/**
 * Programmer = publier avec une date : le moteur envoie toutes les 5 minutes
 * chaque e-mail de segment publié dont la date est passée. Une règle trop
 * lâche ici, et l'e-mail part chez de vrais contacts au mauvais moment.
 */
final class EmailScheduleRulesTest extends TestCase
{
    private \DateTimeZone $tz;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->tz  = new \DateTimeZone('Europe/Paris');
        $this->now = new \DateTimeImmutable('2026-11-12 10:00:00', $this->tz);
    }

    private function emailDeSegment(): Email
    {
        $segment = new LeadList();
        $segment->setName('Clients');
        $email = new Email();
        $email->setEmailType('list');
        $email->addList($segment);

        return $email;
    }

    public function testSansHoraireCEstDesQuePossible(): void
    {
        self::assertEquals($this->now, EmailScheduleRules::sendAt(null, $this->tz, $this->now));
        self::assertEquals($this->now, EmailScheduleRules::sendAt('  ', $this->tz, $this->now));
    }

    public function testUnHoraireFuturEstLuAuFuseauDeLInstance(): void
    {
        $date = EmailScheduleRules::sendAt('2026-11-13T09:00', $this->tz, $this->now);

        self::assertInstanceOf(\DateTimeImmutable::class, $date);
        self::assertSame('2026-11-13 09:00 Europe/Paris', $date->format('Y-m-d H:i e'));
    }

    public function testUnHorairePasseOuTropLointainOuMalFormeEstRefuse(): void
    {
        foreach (['2026-11-11T09:00', '2027-12-01T09:00', 'demain 9h', '13/11/2026 09:00', '2026-11-13T09:00+02:00'] as $brut) {
            self::assertSame('bad_send_at', EmailScheduleRules::sendAt($brut, $this->tz, $this->now), $brut);
        }
    }

    public function testQuelquesMinutesDeRetardSontTolerees(): void
    {
        // L'utilisateur a mis deux minutes à cliquer « Confirmer ».
        self::assertInstanceOf(\DateTimeImmutable::class, EmailScheduleRules::sendAt('2026-11-12T09:58', $this->tz, $this->now));
    }

    public function testUnEmailDeSegmentAvecSegmentSeProgramme(): void
    {
        self::assertNull(EmailScheduleRules::refus($this->emailDeSegment(), $this->now));
    }

    public function testUnEmailModeleNeSeProgrammePas(): void
    {
        $email = $this->emailDeSegment();
        $email->setEmailType('template');

        self::assertSame('not_segment_email', EmailScheduleRules::refus($email, $this->now));
    }

    public function testSansSegmentPublierNEnverraitRien(): void
    {
        $email = new Email();
        $email->setEmailType('list');

        self::assertSame('no_segment', EmailScheduleRules::refus($email, $this->now));
    }

    public function testUneVarianteSuitSonParent(): void
    {
        $email = $this->emailDeSegment();
        $email->setVariantParent($this->emailDeSegment());

        self::assertSame('is_variant', EmailScheduleRules::refus($email, $this->now));
    }

    public function testUneFinDePublicationAvantLEnvoiLEmpecherait(): void
    {
        // Le moteur ne tient compte de la fin de publication d'un e-mail de
        // segment que s'il « continue l'envoi » (Email::getPublishDown).
        $email = $this->emailDeSegment();
        $email->setContinueSending(true);
        $email->setPublishDown(new \DateTime('2026-11-12 18:00:00', $this->tz));

        self::assertSame('publish_down_before', EmailScheduleRules::refus($email, new \DateTimeImmutable('2026-11-13 09:00', $this->tz)));
        self::assertNull(EmailScheduleRules::refus($email, new \DateTimeImmutable('2026-11-12 17:00', $this->tz)));
    }

    public function testSansContinuerLEnvoiLaFinDePublicationEstIgnoreeCommeLeMoteur(): void
    {
        $email = $this->emailDeSegment();
        $email->setPublishDown(new \DateTime('2026-11-12 18:00:00', $this->tz));

        self::assertNull(EmailScheduleRules::refus($email, new \DateTimeImmutable('2026-11-13 09:00', $this->tz)));
    }

    public function testLeLibelleDitLaDateOuDesMaintenant(): void
    {
        self::assertSame('dès maintenant (sous 5 minutes)', EmailScheduleRules::libelle($this->now, $this->now));
        self::assertSame('le 13/11/2026 à 09:00', EmailScheduleRules::libelle(new \DateTimeImmutable('2026-11-13 09:00', $this->tz), $this->now));
    }
}
