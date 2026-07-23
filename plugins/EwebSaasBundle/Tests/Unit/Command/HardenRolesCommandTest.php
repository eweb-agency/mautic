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
 * Contrat white-label des rôles tenant.
 *
 * Le Marketplace nomme le produit amont et pointe vers sa documentation :
 * il reste interdit. La page Plugins, elle, ne le nomme nulle part et
 * porte tous les connecteurs natifs (Zapier, CRM…) : elle est rendue au
 * rôle propriétaire, reconnu à sa permission webhook — que les fixtures
 * accordent au propriétaire et refusent au membre.
 *
 * La commande tourne à chaque démarrage de conteneur : elle doit rester
 * strictement idempotente.
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

    /** @param Role[] $roles */
    private function makeEntityManager(array $roles): EntityManagerInterface
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->with(['isAdmin' => false])->willReturn($roles);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        return $em;
    }

    /** @return string[] */
    private function bundlesOf(Role $role): array
    {
        $bundles = array_map(
            static fn (Permission $permission): string => $permission->getBundle(),
            $role->getPermissions()->getValues(),
        );
        sort($bundles);

        return $bundles;
    }

    public function testDropsMarketplaceButKeepsPluginsOnOwnerRole(): void
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

        $em = $this->makeEntityManager([$role]);
        $em->expects($this->once())->method('remove');
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $tester = $this->makeCommandTester($em);
        $this->assertSame(0, $tester->execute([]));

        $this->assertSame(
            ['plugin', 'webhook'],
            $this->bundlesOf($role),
            'seul le marketplace est retiré ; les connecteurs restent',
        );
        $this->assertSame(
            ['plugin' => ['plugins' => 1024], 'webhook' => ['webhooks' => 1024]],
            $role->getRawPermissions(),
            'le tableau brut ne perd que le marketplace',
        );

        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(1, $report['permissionsRemoved']);
        $this->assertSame(0, $report['permissionsGranted']);
    }

    public function testRestoresPluginsOnOwnerRoleThatLostIt(): void
    {
        $role = new Role();
        $role->setName('Owner');
        $role->setIsAdmin(false);
        $role->getPermissions()->add($this->makePermission('webhook', 'webhooks'));

        $em = $this->makeEntityManager([$role]);
        $em->expects($this->never())->method('remove');
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $tester = $this->makeCommandTester($em);
        $this->assertSame(0, $tester->execute([]));

        $this->assertSame(
            ['plugin', 'webhook'],
            $this->bundlesOf($role),
            'le tenant retrouve ses connecteurs natifs',
        );

        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(0, $report['permissionsRemoved']);
        $this->assertSame(1, $report['permissionsGranted']);
    }

    public function testLeavesMemberRoleAlone(): void
    {
        // Pas de permission webhook : ce n'est pas un rôle propriétaire, on
        // ne lui ouvre pas la configuration des connecteurs.
        $role = new Role();
        $role->setName('Member');
        $role->setIsAdmin(false);
        $role->getPermissions()->add($this->makePermission('email', 'emails'));

        $em = $this->makeEntityManager([$role]);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $tester = $this->makeCommandTester($em);
        $this->assertSame(0, $tester->execute([]));

        $this->assertSame(['email'], $this->bundlesOf($role));

        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(0, $report['permissionsRemoved']);
        $this->assertSame(0, $report['permissionsGranted']);
    }

    public function testIsIdempotentOnAnAlreadyReconciledOwnerRole(): void
    {
        $role = new Role();
        $role->setName('Owner');
        $role->setIsAdmin(false);
        $role->getPermissions()->add($this->makePermission('webhook', 'webhooks'));
        $role->getPermissions()->add($this->makePermission('plugin', 'plugins'));

        $em = $this->makeEntityManager([$role]);
        $em->expects($this->never())->method('remove');
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $tester = $this->makeCommandTester($em);
        $this->assertSame(0, $tester->execute([]));

        $report = json_decode($tester->getDisplay(), true);
        $this->assertSame(1, $report['rolesScanned']);
        $this->assertSame(0, $report['permissionsRemoved']);
        $this->assertSame(0, $report['permissionsGranted']);
    }
}
