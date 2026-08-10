<?php

declare(strict_types=1);

namespace MauticPlugin\EwebAiBundle\Tests\Unit\Service;

use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\LeadList;
use MauticPlugin\EwebAiBundle\Service\AiAbTestService;
use PHPUnit\Framework\TestCase;

/**
 * Le clonage d'e-mail est un champ de mines (cartographie du 06/08) : chaque
 * test ci-dessous grave l'un des pièges — perdre l'un de ces verrous, c'est
 * revivre le bug correspondant en silence.
 */
final class AiAbTestServiceTest extends TestCase
{
    /** @var list<Email> */
    private array $saved = [];

    private function service(): AiAbTestService
    {
        $model = $this->createMock(EmailModel::class);
        $model->method('saveEntity')->willReturnCallback(function (Email $email): void {
            $this->saved[] = $email;
        });

        return new AiAbTestService($model);
    }

    private function parent(): Email
    {
        $parent = new Email();
        $this->setId($parent, 42);
        $parent->setName('Newsletter juillet');
        $parent->setSubject('Objet original');
        $parent->setEmailType('list');
        $parent->setLists([new LeadList()]);

        return $parent;
    }

    private function setId(Email $email, int $id): void
    {
        $ref = new \ReflectionProperty(Email::class, 'id');
        $ref->setValue($email, $id);
    }

    public function testLesVariantesSontDesClonesNatifsComplets(): void
    {
        $parent = $this->parent();
        $result = $this->service()->createSubjectVariants($parent, ['Objet B', 'Objet C'], true);

        self::assertCount(2, $result['created']);
        self::assertCount(2, $this->saved);

        [$b, $c] = $result['created'];

        // ⚠️ __clone met emailType à null ; sans réapplication, saveEntity
        // force 'template' ET VIDE LES SEGMENTS. Le piège le plus cher.
        self::assertSame('list', $b->getEmailType());
        self::assertSame($parent, $b->getVariantParent());
        self::assertSame('Objet B', $b->getSubject());
        self::assertSame('Objet C', $c->getSubject());
        // La lettre continue la fratrie : parent = A.
        self::assertSame('Newsletter juillet — B', $b->getName());
        self::assertSame('Newsletter juillet — C', $c->getName());
        self::assertTrue($b->getIsPublished());
    }

    public function testLaRepartitionDuTraficNAffamePasLeParent(): void
    {
        $result = $this->service()->createSubjectVariants($this->parent(), ['B', 'C', 'D', 'E'], true);

        // 5 branches (parent compris) : 20 % chacune — le parent garde le
        // reste (100 - 4×20 = 20), jamais zéro.
        foreach ($result['created'] as $variant) {
            self::assertSame(20, $variant->getVariantSettings()['weight']);
            self::assertSame(AiAbTestService::DEFAULT_CRITERIA, $variant->getVariantSettings()['winnerCriteria']);
        }
    }

    public function testLesListesSontRecopieesPasPartagees(): void
    {
        $parent = $this->parent();
        $result = $this->service()->createSubjectVariants($parent, ['Objet B'], true);

        $clone = $result['created'][0];
        self::assertNotSame($parent->getLists(), $clone->getLists(), 'le clone superficiel PHP partage l instance de collection : muter la variante muterait le parent');
        self::assertSame($parent->getLists()->toArray(), $clone->getLists()->toArray());
    }

    public function testLesDoublonsEtLeSurplusSontEcartesEtDits(): void
    {
        $parent = $this->parent();
        $result = $this->service()->createSubjectVariants(
            $parent,
            ['Objet original', 'Objet B', 'objet b', 'C', 'D', 'E', 'F'],
            true
        );

        // « Objet original » = l'objet du parent ; « objet b » = doublon
        // insensible à la casse ; « F » dépasse le plafond de 4 variantes.
        self::assertCount(4, $result['created']);
        $reasons = array_column($result['skipped'], 'reason');
        self::assertContains('duplicate', $reasons);
        self::assertContains('too_many', $reasons);
    }

    public function testUnParentVarianteOuTraductionEstRefuse(): void
    {
        $parent = $this->parent();
        $other  = $this->parent();
        $parent->setVariantParent($other);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->createSubjectVariants($parent, ['B'], true);
    }

    public function testSansDroitDePublierLesVariantesNaissentDepubliees(): void
    {
        $result = $this->service()->createSubjectVariants($this->parent(), ['Objet B'], false);

        self::assertFalse($result['created'][0]->getIsPublished());
    }

    public function testUneFratrieExistanteImposeSonCritereEtSonBudget(): void
    {
        $parent   = $this->parent();
        $existing = new Email();
        $this->setId($existing, 43);
        $existing->setSubject('Variante historique');
        $existing->setVariantSettings(['weight' => 60, 'winnerCriteria' => 'email.clickthrough']);
        $parent->addVariantChild($existing);

        $result = $this->service()->createSubjectVariants($parent, ['Objet C'], true);

        $clone = $result['created'][0];
        // Critère hétérogène = Mautic n'affiche AUCUN résultat : on adopte
        // celui de la fratrie.
        self::assertSame('email.clickthrough', $clone->getVariantSettings()['winnerCriteria']);
        // Budget restant : 100 - 60 = 40, une nouvelle variante -> min(33, 40).
        self::assertSame(33, $clone->getVariantSettings()['weight']);
        // La lettre continue après la variante existante (B) : C.
        self::assertSame('Newsletter juillet — C', $clone->getName());
    }
}
