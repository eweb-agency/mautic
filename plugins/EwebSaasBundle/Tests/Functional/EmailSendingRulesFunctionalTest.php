<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Functional;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\Lead as CampaignLead;
use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\FrequencyRule;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\ListLead;
use PHPUnit\Framework\Assert;

/**
 * Ce que la page de désabonnement promet, prouvé sur le vrai moteur d'envoi :
 * la pause, le rythme, les e-mails retenus, les envois programmés et le
 * désabonnement dans une campagne.
 */
final class EmailSendingRulesFunctionalTest extends MauticMysqlTestCase
{
    public function testUnePauseEnCoursRetientUnEnvoiDeSegment(): void
    {
        $lead  = $this->contact();
        $this->pause($lead);
        $email = $this->email('Pendant la pause');

        $this->sendToSegment($email, $lead);

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    public function testUnePauseEnCoursRetientUnEnvoiMarketingDeCampagne(): void
    {
        $lead  = $this->contact();
        $this->pause($lead);
        $email = $this->email('Pendant la pause');

        $this->send($email, $lead, ['email_type' => MailHelper::EMAIL_TYPE_MARKETING]);

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    public function testUnePauseNeRetientPasUnEnvoiTransactionnel(): void
    {
        $lead  = $this->contact();
        $this->pause($lead);
        $email = $this->email('Facture');

        $this->send($email, $lead, ['email_type' => MailHelper::EMAIL_TYPE_TRANSACTIONAL]);

        Assert::assertSame(1, $this->sent($email, $lead));
    }

    public function testUnePauseTermineeNeRetientPlusRien(): void
    {
        $lead  = $this->contact();
        $this->pause($lead, '-4 months', '-1 month');
        $email = $this->email('Après la pause');

        $this->sendToSegment($email, $lead);

        Assert::assertSame(1, $this->sent($email, $lead));
    }

    public function testUnePauseAVenirNeRetientRienEncore(): void
    {
        $lead  = $this->contact();
        $this->pause($lead, '+1 week', '+3 months');
        $email = $this->email('Avant la pause');

        $this->sendToSegment($email, $lead);

        Assert::assertSame(1, $this->sent($email, $lead));
    }

    /**
     * La trace annulée sort le contact des destinataires en attente, ne gonfle
     * pas le compteur que voit le client et ne repart jamais.
     */
    public function testUnEnvoiPendantLaPauseLaisseUneTraceQueRienNeRejoue(): void
    {
        $lead  = $this->contact();
        $this->pause($lead);
        $email = $this->email('Pendant la pause');

        $this->sendToSegment($email, $lead);

        Assert::assertSame(MessageQueue::STATUS_CANCELLED, $this->message($email, $lead)->getStatus());
        Assert::assertSame(0, $this->model()->getQueuedCounts($email));
        Assert::assertSame(0, (int) $this->model()->getPendingLeads($email, null, true));
    }

    /**
     * Sinon, trois mois d'e-mails arriveraient d'un coup à la fin de la pause.
     */
    public function testLaFinDeLaPauseNeRejoueRien(): void
    {
        $lead  = $this->contact();
        $rule  = $this->pause($lead);
        $email = $this->scheduledEmail('Pendant la pause', '-1 hour');
        $this->addToSegment($email, $lead);

        $this->testSymfonyCommand('mautic:broadcasts:send', ['--channel' => 'email']);
        Assert::assertSame(0, $this->sent($email, $lead));

        $rule->setPauseToDate(new \DateTime('-1 minute'));
        $this->em->persist($rule);
        $this->em->flush();
        $this->makeDue($email, $lead);

        $this->testSymfonyCommand('mautic:messages:send');
        $this->testSymfonyCommand('mautic:broadcasts:send', ['--channel' => 'email']);

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    public function testUnEmailRetenuQuiEchoitPendantLaPauseEstAnnule(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Retenu avant la pause');
        $this->retained($email, $lead);
        $this->pause($lead);

        $this->testSymfonyCommand('mautic:messages:send');

        Assert::assertSame(0, $this->sent($email, $lead));
        Assert::assertSame(MessageQueue::STATUS_CANCELLED, $this->message($email, $lead)->getStatus());
    }

    /**
     * Le bouton d'annulation de la fiche contact pose ce statut : la tâche
     * planifiée ne doit pas passer outre.
     */
    public function testUnEmailRetenuAnnuleDepuisLaFicheNeRepartPas(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Retenu');
        $this->retained($email, $lead);
        $this->ageSends($lead, '-8 days');

        $message = $this->message($email, $lead);
        $message->setStatus(MessageQueue::STATUS_CANCELLED);
        $this->em->persist($message);
        $this->em->flush();

        $this->testSymfonyCommand('mautic:messages:send');

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    public function testUnEmailRetenuPartApresLEcheance(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Retenu');
        $this->rhythm($lead, FrequencyRule::TIME_WEEK);
        $this->pastSend($lead, '-2 days');

        $this->sendToSegment($email, $lead);

        Assert::assertSame(0, $this->sent($email, $lead), 'Le plafond retient le deuxième e-mail de la semaine.');
        Assert::assertGreaterThan(new \DateTime('+6 days'), $this->message($email, $lead)->getScheduledDate());

        // Pas encore l'heure : rien ne part.
        $this->testSymfonyCommand('mautic:messages:send');
        Assert::assertSame(0, $this->sent($email, $lead));

        $this->ageSends($lead, '-8 days');
        $this->makeDue($email, $lead);

        $this->testSymfonyCommand('mautic:messages:send');

        Assert::assertSame(1, $this->sent($email, $lead));
        Assert::assertSame(MessageQueue::STATUS_SENT, $this->message($email, $lead)->getStatus());
    }

    public function testUnEmailRetenuAttendSiLePlafondEstEncoreAtteint(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Retenu');
        $this->retained($email, $lead);
        $before = clone $this->message($email, $lead)->getScheduledDate();

        $this->testSymfonyCommand('mautic:messages:send');

        Assert::assertSame(0, $this->sent($email, $lead));
        $message = $this->message($email, $lead);
        Assert::assertSame(MessageQueue::STATUS_RESCHEDULED, $message->getStatus());
        Assert::assertSame(1, $message->getAttempts());
        Assert::assertGreaterThan($before, $message->getScheduledDate());
    }

    public function testDeuxEmailsRetenusNePartentPasLaMemeSemaine(): void
    {
        $lead   = $this->contact();
        $first  = $this->email('Retenu 1');
        $second = $this->email('Retenu 2');
        $this->rhythm($lead, FrequencyRule::TIME_WEEK);
        $this->pastSend($lead, '-2 days');
        $this->sendToSegment($first, $lead);
        $this->sendToSegment($second, $lead);

        $this->ageSends($lead, '-8 days');
        $this->makeDue($first, $lead);
        $this->makeDue($second, $lead);

        $this->testSymfonyCommand('mautic:messages:send', ['--batch' => 1]);

        Assert::assertSame(1, $this->sent($first, $lead) + $this->sent($second, $lead));
    }

    public function testUnEmailRetenuNePartPasSiLeContactSEstDesabonne(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Retenu');
        $this->retained($email, $lead);
        $this->ageSends($lead, '-8 days');
        $this->unsubscribe($lead);

        $this->testSymfonyCommand('mautic:messages:send');

        Assert::assertSame(0, $this->sent($email, $lead));
        Assert::assertNotSame(MessageQueue::STATUS_SENT, $this->message($email, $lead)->getStatus());
    }

    /**
     * Ce qui arrive au bout des tentatives : l'e-mail est abandonné pour ce
     * contact, sans jamais dépasser le plafond.
     */
    public function testAuBoutDeSesTentativesUnEmailRetenuEstAbandonne(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Retenu');
        $this->retained($email, $lead);

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->makeDue($email, $lead);
            $this->testSymfonyCommand('mautic:messages:send');
        }

        Assert::assertSame(3, $this->message($email, $lead)->getAttempts());

        $this->ageSends($lead, '-8 days');
        $this->makeDue($email, $lead);
        $this->testSymfonyCommand('mautic:messages:send');

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    /**
     * Le cas réel d'un espace : publié, sans date, jamais envoyé, avec des
     * contacts en attente. La tâche planifiée ne doit pas y toucher.
     */
    public function testLesEnvoisProgrammesIgnorentUnEmailDeSegmentSansDate(): void
    {
        $lead  = $this->contact();
        $email = $this->scheduledEmail('Sans date', null);
        $this->addToSegment($email, $lead);

        $this->testSymfonyCommand('mautic:broadcasts:send', ['--channel' => 'email']);

        Assert::assertSame(0, $this->sent($email, $lead));
        Assert::assertSame(1, (int) $this->model()->getPendingLeads($email, null, true));
    }

    public function testLesEnvoisProgrammesPartentUneFoisLaDatePassee(): void
    {
        $lead  = $this->contact();
        $email = $this->scheduledEmail('Daté', '-1 hour');
        $this->addToSegment($email, $lead);

        $this->testSymfonyCommand('mautic:broadcasts:send', ['--channel' => 'email']);

        Assert::assertSame(1, $this->sent($email, $lead));
    }

    public function testLesEnvoisProgrammesAttendentLaDate(): void
    {
        $lead  = $this->contact();
        $email = $this->scheduledEmail('À venir', '+1 hour');
        $this->addToSegment($email, $lead);

        $this->testSymfonyCommand('mautic:broadcasts:send', ['--channel' => 'email']);

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    /**
     * Sinon, chaque nouveau contact d'un segment recevrait d'anciens e-mails.
     */
    public function testUnContactArriveApresLaDateNeRecoitPasLEmailProgramme(): void
    {
        $lead  = $this->contact();
        $email = $this->scheduledEmail('Daté', '-1 hour');
        $this->addToSegment($email, $lead, '-1 minute');

        $this->testSymfonyCommand('mautic:broadcasts:send', ['--channel' => 'email']);

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    public function testLesEnvoisProgrammesRespectentLaFinDePublication(): void
    {
        $lead  = $this->contact();
        $email = $this->scheduledEmail('Expiré', '-2 days');
        $email->setPublishDown(new \DateTime('-1 day'));
        $this->addToSegment($email, $lead, '-1 week');

        $this->testSymfonyCommand('mautic:broadcasts:send', ['--channel' => 'email']);

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    public function testLesEnvoisProgrammesRespectentLeDesabonnement(): void
    {
        $lead  = $this->contact();
        $email = $this->scheduledEmail('Daté', '-1 hour');
        $this->addToSegment($email, $lead);
        $this->unsubscribe($lead);

        $this->testSymfonyCommand('mautic:broadcasts:send', ['--channel' => 'email']);

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    public function testUneActionDeCampagneSansTypeRespecteLeDesabonnement(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Campagne');
        $this->unsubscribe($lead);

        $this->runCampaign($email, $lead, []);

        Assert::assertSame(0, $this->sent($email, $lead));
    }

    public function testUneActionDeCampagneSansTypeRespecteLeRythme(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Campagne');
        $this->rhythm($lead, FrequencyRule::TIME_WEEK);
        $this->pastSend($lead, '-2 days');

        $this->runCampaign($email, $lead, []);

        Assert::assertSame(0, $this->sent($email, $lead));
        Assert::assertNotNull($this->message($email, $lead));
    }

    public function testUneActionDeCampagneSansTypeEnvoieAUnContactJoignable(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Campagne');

        $this->runCampaign($email, $lead, []);

        Assert::assertSame(1, $this->sent($email, $lead));
    }

    public function testUneActionDeCampagneTransactionnelleResteTransactionnelle(): void
    {
        $lead  = $this->contact();
        $email = $this->email('Campagne');
        $this->unsubscribe($lead);

        $this->runCampaign($email, $lead, ['email_type' => MailHelper::EMAIL_TYPE_TRANSACTIONAL]);

        Assert::assertSame(1, $this->sent($email, $lead));
    }

    private function model(): EmailModel
    {
        return $this->getContainer()->get(EmailModel::class);
    }

    private function contact(): Lead
    {
        $lead = new Lead();
        $lead->setEmail('camille@exemple.fr');
        $this->em->getRepository(Lead::class)->saveEntity($lead);

        return $lead;
    }

    private function email(string $name): Email
    {
        $email = new Email();
        $email->setName($name);
        $email->setSubject($name);
        $email->setEmailType('template');
        $email->setCustomHtml('<html><body>'.$name.'</body></html>');
        $email->setIsPublished(true);
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    private function scheduledEmail(string $name, ?string $publishUp): Email
    {
        $email = $this->email($name);
        $email->setEmailType('list');
        $email->setPublishUp(null === $publishUp ? null : new \DateTime($publishUp));
        $this->em->persist($email);
        $this->em->flush();

        return $email;
    }

    private function addToSegment(Email $email, Lead $lead, string $joined = '-3 days'): void
    {
        $segment = $this->segment();
        $email->addList($segment);
        $this->em->persist($email);

        $member = new ListLead();
        $member->setList($segment);
        $member->setLead($lead);
        $member->setDateAdded(new \DateTime($joined));
        $this->em->persist($member);
        $this->em->flush();
    }

    private function segment(): LeadList
    {
        $segment = new LeadList();
        $segment->setName('Segment');
        $segment->setAlias('segment-'.uniqid());
        $segment->setPublicName('Segment');
        $this->em->persist($segment);
        $this->em->flush();

        return $segment;
    }

    private function rule(Lead $lead): FrequencyRule
    {
        $rule = $this->em->getRepository(FrequencyRule::class)->findOneBy(['lead' => $lead->getId(), 'channel' => 'email']);

        if (null === $rule) {
            $rule = new FrequencyRule();
            $rule->setLead($lead);
            $rule->setChannel('email');
            $rule->setDateAdded(new \DateTime());
        }

        return $rule;
    }

    private function pause(Lead $lead, string $from = '-1 day', string $to = '+3 months'): FrequencyRule
    {
        $rule = $this->rule($lead);
        $rule->setPauseFromDate(new \DateTime($from));
        $rule->setPauseToDate(new \DateTime($to));
        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    private function rhythm(Lead $lead, string $time): void
    {
        $rule = $this->rule($lead);
        $rule->setFrequencyNumber(1);
        $rule->setFrequencyTime($time);
        $this->em->persist($rule);
        $this->em->flush();
    }

    private function unsubscribe(Lead $lead): void
    {
        $dnc = new DoNotContact();
        $dnc->setLead($lead);
        $dnc->setChannel('email');
        $dnc->setReason(DoNotContact::UNSUBSCRIBED);
        $dnc->setDateAdded(new \DateTime());
        $this->em->persist($dnc);
        $this->em->flush();
    }

    /**
     * L'e-mail déjà reçu qui fait atteindre le plafond.
     */
    private function pastSend(Lead $lead, string $when): void
    {
        $stat = new Stat();
        $stat->setTrackingHash(uniqid('hachage'));
        $stat->setEmailAddress((string) $lead->getEmail());
        $stat->setLead($lead);
        $stat->setDateSent(new \DateTime($when));
        $stat->setEmail($this->email('Déjà reçu'));
        $this->em->persist($stat);
        $this->em->flush();
    }

    private function ageSends(Lead $lead, string $when): void
    {
        foreach ($this->em->getRepository(Stat::class)->findBy(['lead' => $lead->getId()]) as $stat) {
            $stat->setDateSent(new \DateTime($when));
            $this->em->persist($stat);
        }

        $this->em->flush();
    }

    /**
     * Un e-mail retenu par le rythme hebdomadaire, arrivé à échéance.
     */
    private function retained(Email $email, Lead $lead): void
    {
        $this->rhythm($lead, FrequencyRule::TIME_WEEK);
        $this->pastSend($lead, '-2 days');
        $this->sendToSegment($email, $lead);
        $this->makeDue($email, $lead);
    }

    private function makeDue(Email $email, Lead $lead): void
    {
        $message = $this->message($email, $lead);

        if ($message instanceof MessageQueue) {
            $message->setScheduledDate(new \DateTime('-1 minute'));
            $message->setLastAttempt(new \DateTime('-1 minute'));
            $this->em->persist($message);
            $this->em->flush();
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(Email $email, Lead $lead, array $options): void
    {
        $this->model()->sendEmail($email, [['id' => $lead->getId(), 'email' => $lead->getEmail(), 'firstname' => '', 'lastname' => '']], $options);
    }

    private function sendToSegment(Email $email, Lead $lead): void
    {
        $this->send($email, $lead, ['listId' => $this->segment()->getId()]);
    }

    private function sent(Email $email, Lead $lead): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.'email_stats WHERE email_id = ? AND lead_id = ?',
            [$email->getId(), $lead->getId()]
        );
    }

    private function message(Email $email, Lead $lead): ?MessageQueue
    {
        // Le modèle écrit en dehors de cet objet : toujours relire la base.
        $this->em->clear(MessageQueue::class);

        return $this->em->getRepository(MessageQueue::class)->findOneBy(['channel' => 'email', 'channelId' => $email->getId(), 'lead' => $lead->getId()]);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function runCampaign(Email $email, Lead $lead, array $properties): void
    {
        $campaign = new Campaign();
        $campaign->setName('Campagne');
        $campaign->setIsPublished(true);
        $this->em->persist($campaign);

        $event = new Event();
        $event->setCampaign($campaign);
        $event->setName('Envoyer un e-mail');
        $event->setType('email.send');
        $event->setChannel('email');
        $event->setChannelId($email->getId());
        $event->setEventType('action');
        $event->setTriggerMode('immediate');
        $event->setOrder(1);
        $event->setProperties(array_merge(['email' => $email->getId()], $properties));
        $this->em->persist($event);
        $campaign->addEvent(0, $event);

        $member = new CampaignLead();
        $member->setCampaign($campaign);
        $member->setLead($lead);
        $member->setDateAdded(new \DateTime());
        $this->em->persist($member);
        $campaign->addLead(0, $member);
        $this->em->flush();

        $this->testSymfonyCommand('mautic:campaigns:trigger', ['--campaign-id' => $campaign->getId()]);
    }
}
