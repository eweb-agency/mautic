<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Controller;

use GuzzleHttp\Client;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\EwebAiBundle\Controller\AiEmailScheduleController;
use MauticPlugin\EwebAiBundle\Service\AiCopilotService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Le modèle ne peut jamais programmer un envoi : il prépare, l'utilisateur
 * confirme. Ces tests verrouillent le contrat en deux temps de l'endpoint :
 * une PRÉVISUALISATION n'écrit rien (ni sauvegarde, ni crédit, ni date laissée
 * sur l'entité), seule la CONFIRMATION publie et pose la date.
 */
final class AiEmailScheduleControllerTest extends TestCase
{
    private ?string $previousKey = null;

    /** @var list<string> */
    private array $journal = [];

    private Email $email;

    /** Date de publication vue par le moteur au moment du décompte. */
    private ?\DateTimeInterface $publishUpAuDecompte = null;

    protected function setUp(): void
    {
        $this->previousKey            = $_ENV['SENDLY_ANTHROPIC_KEY'] ?? null;
        $_ENV['SENDLY_ANTHROPIC_KEY'] = 'test-key';

        $segment = new LeadList();
        $segment->setName('Clients actifs');
        $this->email = new Email();
        $this->email->setName('Newsletter de novembre');
        $this->email->setSubject('Nos nouveautés');
        $this->email->setEmailType('list');
        $this->email->addList($segment);
        $this->email->setIsPublished(false);
        $this->journal = [];
    }

    protected function tearDown(): void
    {
        if (null === $this->previousKey) {
            unset($_ENV['SENDLY_ANTHROPIC_KEY']);
        } else {
            $_ENV['SENDLY_ANTHROPIC_KEY'] = $this->previousKey;
        }
    }

    private function controller(bool $peutPublier = true): AiEmailScheduleController
    {
        $model = $this->createMock(EmailModel::class);
        $model->method('getEntity')->willReturnCallback(fn ($id) => 7 === (int) $id ? $this->email : null);
        $model->method('getPendingLeads')->willReturnCallback(function (Email $e): int {
            $this->publishUpAuDecompte = $e->getPublishUp() ? clone $e->getPublishUp() : null;

            return 42;
        });
        $model->method('saveEntity')->willReturnCallback(function (): void { $this->journal[] = 'save'; });

        $security = $this->createMock(CorePermissions::class);
        $security->method('hasEntityAccess')->willReturnCallback(
            static fn (string $own): bool => $peutPublier || !str_contains($own, 'publish')
        );

        $audit = $this->createMock(AuditLogModel::class);
        $audit->method('writeToLog')->willReturnCallback(function (array $e): void { $this->journal[] = 'credit:'.$e['action']; });

        $params = $this->createMock(CoreParametersHelper::class);
        $params->method('get')->willReturn('Europe/Paris');

        return new AiEmailScheduleController(
            new AiCopilotService($this->createMock(Client::class), new NullLogger()),
            $model,
            $security,
            $audit,
            $params,
        );
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function appel(array $corps, bool $peutPublier = true): array
    {
        $request = new Request([], [], [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], json_encode($corps, JSON_THROW_ON_ERROR));
        $reponse = $this->controller($peutPublier)->scheduleAction($request);

        return [$reponse->getStatusCode(), json_decode((string) $reponse->getContent(), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function demain(): string
    {
        return (new \DateTimeImmutable('tomorrow 09:00', new \DateTimeZone('Europe/Paris')))->format('Y-m-d\TH:i');
    }

    public function testLaPrevisualisationNEcritRienEtCompteLesDestinatairesALaDateDemandee(): void
    {
        [$statut, $rep] = $this->appel(['email_id' => 7, 'send_at' => $this->demain()]);

        self::assertSame(200, $statut);
        self::assertSame('schedule', $rep['mode']);
        self::assertSame('Newsletter de novembre', $rep['name']);
        self::assertSame(['Clients actifs'], $rep['segments']);
        self::assertSame(42, $rep['recipients']);
        self::assertArrayNotHasKey('done', $rep);
        // Rien d'écrit, rien de crédité, et l'entité n'a pas gardé la date.
        self::assertSame([], $this->journal);
        self::assertFalse($this->email->isPublished(false));
        self::assertNull($this->email->getPublishUp());
        // Mais le décompte a bien vu la date demandée (règle du moteur : les
        // contacts entrés dans le segment avant la publication).
        self::assertSame(str_replace('T', ' ', $this->demain()), $this->publishUpAuDecompte?->format('Y-m-d H:i'));
    }

    public function testSeuleLaConfirmationPublieEtPoseLaDate(): void
    {
        [$statut, $rep] = $this->appel(['email_id' => 7, 'send_at' => $this->demain(), 'confirm' => true]);

        self::assertSame(200, $statut);
        self::assertTrue($rep['done']);
        self::assertSame(['save', 'credit:schedule_email'], $this->journal);
        self::assertTrue($this->email->isPublished(false));
        self::assertSame(str_replace('T', ' ', $this->demain()), $this->email->getPublishUp()->format('Y-m-d H:i'));
    }

    public function testAnnulerDepublieSeulementApresConfirmation(): void
    {
        $this->email->setIsPublished(true);

        [, $apercu] = $this->appel(['email_id' => 7, 'cancel' => true]);
        self::assertSame('cancel', $apercu['mode']);
        self::assertTrue($this->email->isPublished(false), 'la prévisualisation ne dépublie pas');
        self::assertSame([], $this->journal);

        [, $rep] = $this->appel(['email_id' => 7, 'cancel' => true, 'confirm' => true]);
        self::assertTrue($rep['done']);
        self::assertFalse($this->email->isPublished(false));
        self::assertSame(['save'], $this->journal);
    }

    public function testSansDroitDePublierRienNePart(): void
    {
        [$statut, $rep] = $this->appel(['email_id' => 7, 'send_at' => $this->demain(), 'confirm' => true], false);

        self::assertSame(403, $statut);
        self::assertSame('forbidden', $rep['error']);
        self::assertSame([], $this->journal);
        self::assertFalse($this->email->isPublished(false));
    }

    public function testUnEmailIntrouvableOuUnHoraireInvalideEstRefuseAvecSaRaison(): void
    {
        [$statut, $rep] = $this->appel(['email_id' => 99, 'confirm' => true]);
        self::assertSame([422, 'email_not_found'], [$statut, $rep['error']]);

        [$statut, $rep] = $this->appel(['email_id' => 7, 'send_at' => '2020-01-01T09:00', 'confirm' => true]);
        self::assertSame([422, 'bad_send_at'], [$statut, $rep['error']]);
        self::assertSame([], $this->journal);
    }

    public function testUnEmailModeleNeSeProgrammePas(): void
    {
        $this->email->setEmailType('template');

        [$statut, $rep] = $this->appel(['email_id' => 7, 'confirm' => true]);

        self::assertSame([422, 'not_segment_email'], [$statut, $rep['error']]);
        self::assertSame([], $this->journal);
    }
}
