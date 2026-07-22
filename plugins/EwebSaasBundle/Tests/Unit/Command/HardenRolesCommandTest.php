<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Mautic\UserBundle\Entity\Permission;
use Mautic\UserBundle\Entity\Role;
use MauticPlugin\EwebSaasBundle\Command\HardenRolesCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Contrat white-label : les rôles non-admin ne doivent jamais conserver
 * les permissions plugin/marketplace (écrans qui exposent le nom du
 * produit amont), et la commande doit être strictement idempotente.
 */
class HardenRolesCommandTest extends TestCase
{
    private function makePermission(string $bundle, string $name): Permission
    {
        $permission = new Permission();
        $permission->setBundle($bundle);
        $permission->setName($name);
        $permission->setBitwise(1024);

        return $permission;
    }

    private function makeCommandTester(EntityManagerInterface $em): CommandTester
    {
        return new CommandTester(new HardenRolesCommand($em));
    }

    public function testRemovesForbiddenPermissionsFromNonAdminRole(): void
    {
        $role = new Role();
        $role->setName('Owner');
        $role->setIsAdmin(false);
        $role->getPermissions()->add($this->makePermission('plugin', 'plugins'));
        $role->getPermissions()->add($this->makePermission('marketplace', 'packages'));
        $role->getPermissions()->add($this->makePermission('webhook', 'webhooks'));
        $role->setRawPermissions([
            'plugin'      => ['plugins' => 1024],
            'marketplace' => ['packages' => 1024],
            'webhook'     => ['webhooks' => 1024],
        ]);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->with(['isAdmin' => false])->willReturn([$role]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects($this->exactly(2))->method('remove');
        $em->expects($this->once())->method('flush');

        $tester = $this->makeCommandTester($em);
        $this->assertSame(0, $tester->execute([]));

        $remaining = array_map(
            static fn (Permission $permission): string => $permission->getBundle(),
            $role->getPermissions()->getValues(),
        );
        $this->assertSame(['webhook'], $remaining, 'seul webhook survit');
        $this->assertSame(
            ['webhook' => ['webhooks' => 1024]],
            $role->getRawPermissions(),
            'le tableau brut est purgé des deux bundles interdits',
        );

        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(1, $report['rolesScanned']);
        $this->assertSame(2, $report['permissionsRemoved']);
    }

    public function testIsIdempotentWhenNothingToRemove(): void
    {
        $role = new Role();
        $role->setName('Member');
        $role->setIsAdmin(false);
        $role->getPermissions()->add($this->makePermission('webhook', 'webhooks'));

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$role]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('flush');

        $tester = $this->makeCommandTester($em);
        $this->assertSame(0, $tester->execute([]));

        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(0, $report['permissionsRemoved']);
    }
}
