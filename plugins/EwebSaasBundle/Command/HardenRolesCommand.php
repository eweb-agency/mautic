<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\UserBundle\Entity\Role;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Strips white-label-breaking permissions from tenant roles.
 *
 * The install fixtures historically granted the Owner role (non-admin)
 * access to the native Plugins page and the Marketplace — both screens
 * expose the upstream product name, which tenants must never see. New
 * installs no longer grant them; this command heals EXISTING tenants.
 *
 * Idempotent and safe to run on every container start (wired into the
 * web entrypoint after migrations): it only ever REMOVES the forbidden
 * grants, never touches admin roles, and exits 0 even when there is
 * nothing to do.
 *
 * Output (stdout, JSON): {"rolesScanned": n, "permissionsRemoved": n}
 */
#[AsCommand(
    name: 'mautic:saas:roles:harden',
    description: 'Remove plugin/marketplace permissions from non-admin roles (white-label enforcement).',
)]
final class HardenRolesCommand extends Command
{
    /** Bundles whose screens leak the upstream product name. */
    public const FORBIDDEN_BUNDLES = ['plugin', 'marketplace'];

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

        foreach ($roles as $role) {
            foreach ($role->getPermissions() as $permission) {
                if (\in_array($permission->getBundle(), self::FORBIDDEN_BUNDLES, true)) {
                    $role->getPermissions()->removeElement($permission);
                    $this->em->remove($permission);
                    ++$removed;
                }
            }

            $raw = $role->getRawPermissions();
            if (\is_array($raw)) {
                $before = \count($raw);
                foreach (self::FORBIDDEN_BUNDLES as $bundle) {
                    unset($raw[$bundle]);
                }
                if (\count($raw) !== $before) {
                    $role->setRawPermissions($raw);
                }
            }
        }

        if ($removed > 0) {
            $this->em->flush();
        }

        $output->writeln((string) json_encode([
            'rolesScanned'       => \count($roles),
            'permissionsRemoved' => $removed,
        ]));

        return Command::SUCCESS;
    }
}
