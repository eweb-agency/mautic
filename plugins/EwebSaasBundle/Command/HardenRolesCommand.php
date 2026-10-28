<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\Permission;
use Mautic\UserBundle\Entity\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconciles tenant roles with the white-label contract.
 *
 * Two screens were stripped from tenant roles together, on the assumption
 * that both leaked the upstream product name. Only one of them does. The
 * Marketplace announces itself as the upstream marketplace and links to
 * the upstream docs. The Plugins page — where a tenant connects Zapier or
 * their CRM — names the engine nowhere: not in the connector list, not in
 * the connection forms. Three labels do, buried in CRM field-mapping, and
 * those are translation strings we own.
 *
 * Blocking Plugins therefore cost tenants every native connector and
 * bought no branding in return. This command now REMOVES the marketplace
 * grant and RESTORES the plugins one.
 *
 * The restore targets owner-level roles only, recognised by the webhook
 * grant the install fixtures give to Owner and withhold from Member: it
 * already marks the role that administers a tenant's integrations.
 * Members keep their narrower reach.
 *
 * Mirrors the fixtures by creating Permission entities and leaving
 * `rawPermissions` alone — that field is written only by the role form,
 * and guessing its shape here would risk corrupting it. Forbidden grants
 * are still pruned from it defensively, as before.
 *
 * It also withholds single grants (FORBIDDEN_PERMISSIONS) from every tenant
 * role. `user:roles` was given to Owner by the install fixtures, and the
 * role form offers the "full system access" switch (isAdmin) to anyone who
 * can edit a role: a tenant could promote its own role to administrator and
 * reach the configuration (shared sending key in clear), plugins and
 * marketplace. Tenants never manage roles in the instance: members and
 * their roles come from the portal through SSO.
 *
 * Forbidden grants are pruned from `rawPermissions` in both shapes it can
 * hold: the form's flat `bundle:name` keys (what `generatePermissions()`
 * reads back when the role is saved again) and the nested `bundle => name`
 * map.
 *
 * Idempotent and safe to run on every container start (wired into the web
 * entrypoint after migrations): it never touches admin roles and exits 0
 * even when there is nothing to do.
 *
 * Output (stdout, JSON):
 * {"rolesScanned": n, "permissionsRemoved": n, "permissionsGranted": n}
 */
#[AsCommand(
    name: 'mautic:saas:roles:harden',
    description: 'Reconcile tenant roles with the white-label contract (drop marketplace and role management, restore plugins).',
)]
final class HardenRolesCommand extends Command
{
    /** Bundles whose screens leak the upstream product name. */
    public const FORBIDDEN_BUNDLES = ['marketplace'];

    /** Single grants no tenant role may hold. Bundle => [names]. */
    public const FORBIDDEN_PERMISSIONS = ['user' => ['roles']];

    /** Restored to owner-level roles. Bundle => [name => bitwise]. */
    public const OWNER_GRANTS = ['plugin' => ['plugins' => 1024]];

    /** Withheld from Member by the fixtures, so it marks an owner role. */
    private const OWNER_MARKER_BUNDLE = 'webhook';
    private const OWNER_MARKER_NAME   = 'webhooks';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Role[] $roles */
        $roles   = $this->em->getRepository(Role::class)->findBy(['isAdmin' => false]);
        $removed = 0;
        $granted = 0;

        foreach ($roles as $role) {
            foreach ($role->getPermissions() as $permission) {
                if ($this->isForbidden($permission)) {
                    $role->getPermissions()->removeElement($permission);
                    $this->em->remove($permission);
                    ++$removed;
                }
            }

            $raw = $role->getRawPermissions();
            if (\is_array($raw)) {
                $pruned = $this->pruneRaw($raw);
                if ($pruned !== $raw) {
                    $role->setRawPermissions($pruned);
                }
            }

            if (!$this->isOwnerRole($role)) {
                continue;
            }

            foreach (self::OWNER_GRANTS as $bundle => $names) {
                foreach ($names as $name => $bitwise) {
                    if ($this->hasPermission($role, $bundle, $name)) {
                        continue;
                    }

                    $permission = new Permission();
                    $permission->setBundle($bundle);
                    $permission->setName($name);
                    $permission->setBitwise($bitwise);
                    $permission->setRole($role);
                    $role->addPermission($permission);
                    $this->em->persist($permission);
                    ++$granted;
                }
            }
        }

        if ($removed > 0 || $granted > 0) {
            $this->em->flush();
        }

        $output->writeln((string) json_encode([
            'rolesScanned'       => \count($roles),
            'permissionsRemoved' => $removed,
            'permissionsGranted' => $granted,
        ]));

        return Command::SUCCESS;
    }

    private function isForbidden(Permission $permission): bool
    {
        $bundle = $permission->getBundle();

        return \in_array($bundle, self::FORBIDDEN_BUNDLES, true)
            || \in_array($permission->getName(), self::FORBIDDEN_PERMISSIONS[$bundle] ?? [], true);
    }

    /**
     * @param array<mixed> $raw
     *
     * @return array<mixed>
     */
    private function pruneRaw(array $raw): array
    {
        foreach (array_keys($raw) as $key) {
            $bundle = explode(':', (string) $key, 2)[0];
            if (\in_array($bundle, self::FORBIDDEN_BUNDLES, true)) {
                unset($raw[$key]);
            }
        }

        foreach (self::FORBIDDEN_PERMISSIONS as $bundle => $names) {
            foreach ($names as $name) {
                unset($raw[$bundle.':'.$name]);
                if (\is_array($raw[$bundle] ?? null)) {
                    unset($raw[$bundle][$name]);
                    if ([] === $raw[$bundle]) {
                        unset($raw[$bundle]);
                    }
                }
            }
        }

        return $raw;
    }

    private function isOwnerRole(Role $role): bool
    {
        return $this->hasPermission(
            $role,
            self::OWNER_MARKER_BUNDLE,
            self::OWNER_MARKER_NAME,
        );
    }

    private function hasPermission(Role $role, string $bundle, string $name): bool
    {
        foreach ($role->getPermissions() as $permission) {
            if ($permission->getBundle() === $bundle && $permission->getName() === $name) {
                return true;
            }
        }

        return false;
    }
}
