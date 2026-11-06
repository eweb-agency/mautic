<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Functional;

use Mautic\ChannelBundle\Entity\MessageQueue;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Helper\MailHashHelper;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\FrequencyRule;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\PageBundle\Entity\Page;
use PHPUnit\Framework\Assert;

/**
 * Le parcours complet sur le vrai noyau, la vraie base et les vraies routes.
 * `show_contact_preferences` est laissé à faux, valeur de production.
 */
final class EmailPreferencesFunctionalTest extends MauticMysqlTestCase
{
    private const ADDRESS = 'camille@exemple.fr';

    protected function setUp(): void
    {
        $this->configParams['show_contact_preferences'] = 0;
        $this->configParams['show_contact_frequency']   = 0;
        $this->configParams['mailer_from_name']         = 'Atelier Mistral';

        parent::setUp();

        $this->logoutUser();
        $this->client->followRedirects(false);
    }

    public function testLeLienDeLEmailRedirigeSansAdresseEtNeDesabonnePas(): void
    {
        $stat = $this->stat();

        $this->client->request('GET', $this->emailLink($stat));

        $response = $this->client->getResponse();
        Assert::assertSame(302, $response->getStatusCode());
        Assert::assertSame('/email/preferences/'.$stat->getTrackingHash(), parse_url((string) $response->headers->get('Location'), PHP_URL_PATH));
        Assert::assertStringNotContainsString('@', (string) $response->headers->get('Location'));
        Assert::assertStringNotContainsString('exemple.fr', (string) $response->headers->get('Location'));
        Assert::assertCount(0, $this->dnc($stat));
    }

    public function testLePremierEcranProposeLesDeuxChemins(): void
    {
        $stat = $this->stat();

        $crawler = $this->client->request('GET', $this->page($stat));

        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());
        Assert::assertSame('Atelier Mistral', $crawler->filter('.brand')->text());
        Assert::assertSame(self::ADDRESS, $crawler->filter('.address')->text());
        Assert::assertCount(1, $crawler->filter('a.button'));
        Assert::assertCount(1, $crawler->filter('form[method="post"] button.button.secondary'));
        Assert::assertStringNotContainsStringIgnoringCase('mautic', (string) $this->client->getResponse()->getContent());
        Assert::assertStringNotContainsString('@', (string) $crawler->filter('form')->attr('action'));
        Assert::assertSame('no-referrer', $this->client->getResponse()->headers->get('Referrer-Policy'));
        Assert::assertCount(0, $this->dnc($stat));
    }

    public function testRobotQuiSuitTousLesLiensNeDesabonnePersonne(): void
    {
        $stat = $this->stat();

        foreach (['', '/frequency', '/saved', '/resubscribed'] as $screen) {
            $this->client->request('GET', $this->page($stat).$screen);
        }

        Assert::assertCount(0, $this->dnc($stat));
        Assert::assertNull($this->rule($stat));
    }

    /**
     * L'exigence non négociable, sur la vraie route et avec l'adresse complète
     * du lien : la réponse est celle du contrôleur d'origine, sans redirection,
     * et le contact est désabonné dans la requête même.
     */
    public function testLeDesabonnementEnUnClicParEnTeteResteDirect(): void
    {
        $stat = $this->stat();

        $this->client->request('POST', $this->emailLink($stat), ['List-Unsubscribe' => 'One-Click']);

        $response = $this->client->getResponse();
        Assert::assertSame(200, $response->getStatusCode());
        Assert::assertFalse($response->isRedirection());
        Assert::assertStringNotContainsString('<form', (string) $response->getContent());

        $dnc = $this->dnc($stat);
        Assert::assertCount(1, $dnc);
        Assert::assertSame(DoNotContact::UNSUBSCRIBED, $dnc[0]->getReason());
    }

    public function testSeDesabonnerDepuisLePremierEcran(): void
    {
        $stat = $this->stat();

        $this->client->request('POST', $this->page($stat).'/unsubscribe');

        Assert::assertSame(303, $this->client->getResponse()->getStatusCode());
        $dnc = $this->dnc($stat);
        Assert::assertCount(1, $dnc);
        Assert::assertSame(DoNotContact::UNSUBSCRIBED, $dnc[0]->getReason());
        Assert::assertSame('email', $dnc[0]->getChannel());
        Assert::assertSame($stat->getEmail()->getId(), $dnc[0]->getChannelId());

        $crawler = $this->client->request('GET', $this->page($stat));
        Assert::assertStringContainsString('Vous êtes désabonné', $crawler->filter('h1')->text());
        Assert::assertStringContainsString(self::ADDRESS, $crawler->filter('p')->text());
    }

    public function testSeReabonner(): void
    {
        $stat = $this->stat();
        $this->client->request('POST', $this->page($stat).'/unsubscribe');

        $this->client->request('POST', $this->page($stat).'/resubscribe');

        Assert::assertSame(303, $this->client->getResponse()->getStatusCode());
        Assert::assertStringEndsWith('/resubscribed', (string) $this->client->getResponse()->headers->get('Location'));
        Assert::assertCount(0, $this->dnc($stat));
    }

    public function testUnRebondNePeutPasEtreLeveParLeLien(): void
    {
        $stat = $this->stat();
        $this->getContainer()->get(EmailModel::class)->setDoNotContact($stat, 'Rebond', DoNotContact::BOUNCED);

        $crawler = $this->client->request('GET', $this->page($stat));
        Assert::assertCount(0, $crawler->filter('button'));

        $this->client->request('POST', $this->page($stat).'/resubscribe');
        Assert::assertCount(1, $this->dnc($stat));
    }

    public function testEnregistrerUnRythmeEcritLaRegleQueLesEnvoisLisent(): void
    {
        $stat = $this->stat();

        $this->client->request('POST', $this->page($stat).'/frequency', ['rhythm' => 'monthly']);

        Assert::assertSame(303, $this->client->getResponse()->getStatusCode());
        $rule = $this->rule($stat);
        Assert::assertInstanceOf(FrequencyRule::class, $rule);
        Assert::assertSame(1, $rule->getFrequencyNumber());
        Assert::assertSame(FrequencyRule::TIME_MONTH, $rule->getFrequencyTime());
        Assert::assertSame('email', $rule->getChannel());

        $crawler = $this->client->request('GET', $this->page($stat).'/saved');
        Assert::assertStringContainsString('une fois par mois au maximum', $crawler->filter('p')->text());

        $crawler = $this->client->request('GET', $this->page($stat).'/frequency');
        Assert::assertSame('monthly', $crawler->filter('input[checked]')->attr('value'));
    }

    public function testChangerDeRythmeNeCreePasDeDeuxiemeRegle(): void
    {
        $stat = $this->stat();

        $this->client->request('POST', $this->page($stat).'/frequency', ['rhythm' => 'monthly']);
        $this->client->request('POST', $this->page($stat).'/frequency', ['rhythm' => 'weekly']);

        $this->em->clear();
        $rules = $this->em->getRepository(FrequencyRule::class)->findBy(['lead' => $stat->getLead()->getId()]);
        Assert::assertCount(1, $rules);
        Assert::assertSame(FrequencyRule::TIME_WEEK, $rules[0]->getFrequencyTime());
    }

    public function testUnChoixDePauseEstRefuse(): void
    {
        $stat = $this->stat();

        $this->client->request('POST', $this->page($stat).'/frequency', ['rhythm' => 'pause']);

        Assert::assertNull($this->rule($stat));
    }

    /**
     * La preuve de ce que la page promet : envoi de segment, deuxième e-mail
     * de la semaine, le contact est mis en file au lieu de recevoir.
     */
    public function testLeRythmeRetientUnEnvoiDeSegment(): void
    {
        $stat = $this->stat();
        $this->client->request('POST', $this->page($stat).'/frequency', ['rhythm' => 'weekly']);

        $second = $this->email('Deuxième e-mail');
        $this->em->flush();

        $this->getContainer()->get(EmailModel::class)->sendEmail($second, [$this->contact($stat)], ['listId' => 1]);

        Assert::assertSame(0, $this->countStats($second));
        Assert::assertCount(1, $this->em->getRepository(MessageQueue::class)->findBy(['channel' => 'email', 'channelId' => $second->getId()]));
    }

    public function testLeRythmeRetientUnEnvoiMarketingDeCampagne(): void
    {
        $stat = $this->stat();
        $this->client->request('POST', $this->page($stat).'/frequency', ['rhythm' => 'weekly']);

        $second = $this->email('Deuxième e-mail');
        $this->em->flush();

        $this->getContainer()->get(EmailModel::class)->sendEmail($second, [$this->contact($stat)], ['email_type' => MailHelper::EMAIL_TYPE_MARKETING]);

        Assert::assertSame(0, $this->countStats($second));
    }

    /**
     * La limite que le texte de la page respecte (« e-mails marketing ») : un
     * envoi transactionnel n'est pas retenu.
     */
    public function testLeRythmeNeRetientPasUnEnvoiTransactionnel(): void
    {
        $stat = $this->stat();
        $this->client->request('POST', $this->page($stat).'/frequency', ['rhythm' => 'weekly']);

        $second = $this->email('Deuxième e-mail');
        $this->em->flush();

        $this->getContainer()->get(EmailModel::class)->sendEmail($second, [$this->contact($stat)], ['email_type' => MailHelper::EMAIL_TYPE_TRANSACTIONAL]);

        Assert::assertSame(1, $this->countStats($second));
    }

    /**
     * Pourquoi la pause n'est pas proposée : une pause en cours dans la règle
     * n'empêche pas l'envoi d'un segment.
     */
    public function testUnePauseEnregistreeNeRetientAucunEnvoi(): void
    {
        $stat = $this->stat();

        $rule = new FrequencyRule();
        $rule->setLead($stat->getLead());
        $rule->setChannel('email');
        $rule->setDateAdded(new \DateTime());
        $rule->setPauseFromDate(new \DateTime('-1 day'));
        $rule->setPauseToDate(new \DateTime('+3 months'));
        $this->em->persist($rule);

        $segment = new LeadList();
        $segment->setName('Segment');
        $segment->setAlias('segment');
        $segment->setPublicName('Segment');
        $this->em->persist($segment);

        $second = $this->email('Pendant la pause');
        $this->em->flush();

        $this->getContainer()->get(EmailModel::class)->sendEmail($second, [$this->contact($stat)], ['listId' => $segment->getId()]);

        Assert::assertSame(1, $this->countStats($second));
    }

    public function testUnCentreDePreferencesSAfficheMemeSiLeReglageEstAFaux(): void
    {
        $page = new Page();
        $page->setTitle('Préférences');
        $page->setAlias('preferences');
        $page->setTemplate('blank');
        $page->setIsPreferenceCenter(true);
        $page->setIsPublished(true);
        $page->setCustomHtml('<html><body><h1>Centre sur mesure</h1>{saveprefsbutton}</body></html>');
        $this->em->persist($page);

        $email = $this->email('Avec centre');
        $email->setPreferenceCenter($page);
        $stat = $this->stat($email);

        $crawler = $this->client->request('GET', $this->emailLink($stat));

        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());
        Assert::assertSame('Centre sur mesure', $crawler->filter('h1')->text());
        Assert::assertCount(0, $this->dnc($stat));
    }

    public function testUnFormulaireDeDesabonnementGardeSonParcours(): void
    {
        $form = new Form();
        $form->setName('Pourquoi partez-vous');
        $form->setAlias('pourquoi');
        $form->setIsPublished(true);
        $this->em->persist($form);

        $email = $this->email('Avec formulaire');
        $email->setUnsubscribeForm($form);
        $stat = $this->stat($email);

        $crawler = $this->client->request('GET', $this->emailLink($stat));

        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());
        Assert::assertStringContainsString('form/submit?formId='.$form->getId(), (string) $crawler->filter('form')->eq(0)->attr('action'));
        Assert::assertCount(1, $this->dnc($stat));
    }

    public function testSansStatMaisAvecUneSignatureValideOnPeutToujoursPartir(): void
    {
        $lead = $this->lead();
        $this->em->flush();

        $hash = $this->getContainer()->get(MailHashHelper::class)->getEmailHash(self::ADDRESS);
        $this->client->request('GET', '/email/unsubscribe/inconnu/'.rawurlencode(self::ADDRESS).'/'.$hash);

        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());
        Assert::assertCount(1, $this->em->getRepository(DoNotContact::class)->findBy(['lead' => $lead->getId()]));
    }

    public function testUneSignatureFausseNeDesabonnePersonne(): void
    {
        $lead = $this->lead();
        $this->em->flush();

        $this->client->request('GET', '/email/unsubscribe/inconnu/'.rawurlencode(self::ADDRESS).'/fausse');

        Assert::assertCount(0, $this->em->getRepository(DoNotContact::class)->findBy(['lead' => $lead->getId()]));
    }

    public function testUnHachageInconnuNeMontreRien(): void
    {
        $this->stat();

        $this->client->request('GET', '/email/preferences/inconnu');

        Assert::assertSame(404, $this->client->getResponse()->getStatusCode());
        Assert::assertStringNotContainsString(self::ADDRESS, (string) $this->client->getResponse()->getContent());
    }

    public function testChaqueLienNeMontreEtNeModifieQueSonContact(): void
    {
        $camille = $this->stat();
        $autre   = $this->stat($this->email('Autre'), 'autre@exemple.fr', 'hachage-autre');

        $this->client->request('GET', $this->page($autre));
        Assert::assertStringNotContainsString(self::ADDRESS, (string) $this->client->getResponse()->getContent());

        // Un formulaire ne peut pas viser un autre contact que celui du lien.
        $this->client->request('POST', $this->page($autre).'/unsubscribe', ['lead' => $camille->getLead()->getId(), 'email' => self::ADDRESS]);

        Assert::assertCount(0, $this->dnc($camille));
        Assert::assertCount(1, $this->dnc($autre));
    }

    public function testLaPageNePoseAucunCookie(): void
    {
        $stat = $this->stat();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->page($stat));

        Assert::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testLaPageSuitLaLangueDuContact(): void
    {
        $stat = $this->stat(null, self::ADDRESS, 'hachage-premier', 'en_GB');
        $this->forgetContacts();

        $crawler = $this->client->request('GET', $this->page($stat));

        Assert::assertSame('en', $crawler->filter('html')->attr('lang'));
        Assert::assertSame('Would you rather get fewer emails?', $crawler->filter('h1')->text());

        $this->client->request('POST', $this->page($stat).'/frequency', ['rhythm' => 'weekly']);
        $crawler = $this->client->request('GET', $this->page($stat).'/saved');
        Assert::assertStringContainsString('once a week at most', $crawler->filter('p')->text());
    }

    public function testLaPageEstEnFrancaisPourUnContactFrancais(): void
    {
        $stat = $this->stat(null, self::ADDRESS, 'hachage-premier', 'fr');
        $this->forgetContacts();

        $crawler = $this->client->request('GET', $this->page($stat));

        Assert::assertSame('fr', $crawler->filter('html')->attr('lang'));
        Assert::assertStringContainsString('Vous préférez recevoir moins', $crawler->filter('h1')->text());
    }

    /**
     * Une vraie requête relit le contact en base : sans cela, le test verrait
     * les champs encore en mémoire et passerait à tort. C'est ce qui a caché
     * que le contact d'un `Stat` arrive sans sa langue.
     */
    private function forgetContacts(): void
    {
        $this->em->clear();
    }

    private function stat(?Email $email = null, string $address = self::ADDRESS, string $hash = 'hachage-premier', ?string $locale = 'fr'): Stat
    {
        $stat = new Stat();
        $stat->setTrackingHash($hash);
        $stat->setEmailAddress($address);
        $stat->setLead($this->lead($address, $locale));
        $stat->setDateSent(new \DateTime());
        $stat->setEmail($email ?? $this->email('Premier e-mail'));
        $this->em->persist($stat);
        $this->em->flush();

        return $stat;
    }

    private function lead(string $address = self::ADDRESS, ?string $locale = 'fr'): Lead
    {
        $lead = new Lead();
        $lead->setEmail($address);
        $lead->addUpdatedField('preferred_locale', $locale);
        // Le dépôt écrit les champs du contact : persist() seul les ignore.
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

        return $email;
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(Stat $stat): array
    {
        $lead = $stat->getLead();

        return ['id' => $lead->getId(), 'email' => $lead->getEmail(), 'firstname' => '', 'lastname' => ''];
    }

    private function emailLink(Stat $stat): string
    {
        $address = (string) $stat->getEmailAddress();
        $hash    = $this->getContainer()->get(MailHashHelper::class)->getEmailHash($address);

        return sprintf('/email/unsubscribe/%s/%s/%s', $stat->getTrackingHash(), rawurlencode($address), $hash);
    }

    private function page(Stat $stat): string
    {
        return '/email/preferences/'.$stat->getTrackingHash();
    }

    /**
     * @return DoNotContact[]
     */
    private function dnc(Stat $stat): array
    {
        $this->em->clear(DoNotContact::class);

        return $this->em->getRepository(DoNotContact::class)->findBy(['lead' => $stat->getLead()->getId()]);
    }

    private function rule(Stat $stat): ?FrequencyRule
    {
        $this->em->clear(FrequencyRule::class);

        return $this->em->getRepository(FrequencyRule::class)->findOneBy(['lead' => $stat->getLead()->getId(), 'channel' => 'email']);
    }

    private function countStats(Email $email): int
    {
        return count($this->em->getRepository(Stat::class)->findBy(['email' => $email->getId()]));
    }
}
