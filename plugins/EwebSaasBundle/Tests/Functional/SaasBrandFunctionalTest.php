<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\EwebSaasBundle\Service\BrandLogo;
use PHPUnit\Framework\Assert;

/**
 * Le logo envoyé par le portail arrive sur la page vue par les destinataires,
 * par la vraie route, derrière le vrai pare-feu de l'API.
 */
final class SaasBrandFunctionalTest extends MauticMysqlTestCase
{
    /** Un PNG de 1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected bool $authenticateApi = true;

    protected function setUp(): void
    {
        $this->configParams['mailer_from_name'] = 'Atelier Mistral';

        parent::setUp();
    }

    protected function beforeTearDown(): void
    {
        $this->getContainer()->get(BrandLogo::class)->remove();
    }

    public function testLeLogoDuPortailRemplaceLeNomPuisEstRetire(): void
    {
        $page = '/email/preferences/'.$this->stat()->getTrackingHash();

        Assert::assertSame('{"changed":true}', $this->post(['image' => self::PNG]));
        Assert::assertSame('{"changed":false}', $this->post(['image' => self::PNG]), 'Le même logo ne réécrit rien.');

        $crawler = $this->client->request('GET', $page);
        Assert::assertCount(0, $crawler->filter('.brand'));
        Assert::assertSame('Atelier Mistral', $crawler->filter('img.logo')->attr('alt'));
        Assert::assertMatchesRegularExpression('#/media/images/sendly/logo-[0-9a-f]{16}\.png#', (string) $crawler->filter('img.logo')->attr('src'));

        Assert::assertSame('{"changed":true}', $this->post(['image' => null]));

        $crawler = $this->client->request('GET', $page);
        Assert::assertCount(0, $crawler->filter('img.logo'));
        Assert::assertSame('Atelier Mistral', $crawler->filter('.brand')->text());
    }

    public function testUnFichierQuiNEstPasUneImageEstRefuse(): void
    {
        $this->post(['image' => base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]);

        Assert::assertSame(422, $this->client->getResponse()->getStatusCode());
        Assert::assertNull($this->getContainer()->get(BrandLogo::class)->assetPath());
    }

    public function testSansLesBonsIdentifiantsRienNEstEcrit(): void
    {
        $this->client->setServerParameter('PHP_AUTH_PW', 'pas-le-bon');
        $this->post(['image' => self::PNG]);

        Assert::assertSame(401, $this->client->getResponse()->getStatusCode());
        Assert::assertNull($this->getContainer()->get(BrandLogo::class)->assetPath());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): string
    {
        $this->client->request('POST', '/api/saas/v1/brand/logo', [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));

        return (string) $this->client->getResponse()->getContent();
    }

    private function stat(): Stat
    {
        $lead = new Lead();
        $lead->setEmail('camille@exemple.fr');
        $this->em->getRepository(Lead::class)->saveEntity($lead);

        $email = new Email();
        $email->setName('Premier e-mail');
        $email->setSubject('Premier e-mail');
        $email->setEmailType('template');
        $email->setCustomHtml('<html><body>Premier e-mail</body></html>');
        $email->setIsPublished(true);
        $this->em->persist($email);

        $stat = new Stat();
        $stat->setTrackingHash('hachage-logo');
        $stat->setEmailAddress('camille@exemple.fr');
        $stat->setLead($lead);
        $stat->setDateSent(new \DateTime());
        $stat->setEmail($email);
        $this->em->persist($stat);
        $this->em->flush();

        return $stat;
    }
}
