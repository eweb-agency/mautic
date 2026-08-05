<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\ApiBundle\Entity\oAuth2\Client;
use Mautic\UserBundle\Entity\Role;
use OAuth2\OAuth2;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ensures an OAuth2 client (client_credentials grant) exists for saas-core.
 *
 * Idempotent: if a client with the configured name already exists, its
 * credentials are returned as-is. Otherwise a fresh client is created with
 * a freshly generated random_id/secret (handled by FOS in the constructor).
 *
 * Output (stdout, JSON — parsed by the provisioner):
 *   {"clientId": "<id>_<randomId>", "clientSecret": "<secret>", "created": true|false}
 *
 * This command is the single native, supported way for the provisioner to
 * provision machine-to-machine API credentials without touching the DB
 * directly or scraping the admin UI.
 *
 * Usage (from the provisioner, inside the mautic-web container):
 *   php bin/console mautic:saas:oauth:ensure --name=saas-core
 */
#[AsCommand(
    name: 'mautic:saas:oauth:ensure',
    description: 'Ensure a client_credentials OAuth2 client exists for saas-core and print its credentials as JSON.',
)]
final class EnsureOAuthClientCommand extends Command
{
    public const DEFAULT_CLIENT_NAME = 'saas-core';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Logical name of the OAuth2 client', self::DEFAULT_CLIENT_NAME)
            ->addOption('redirect-uri', null, InputOption::VALUE_REQUIRED, 'Redirect URI stored on the client (unused for client_credentials)', 'https://saas-core.internal/oauth/callback')
            ->addOption('rotate', null, InputOption::VALUE_NONE, 'Force a new secret even if a client already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name        = (string) $input->getOption('name');
        $redirectUri = (string) $input->getOption('redirect-uri');
        $rotate      = (bool) $input->getOption('rotate');

        $repo   = $this->em->getRepository(Client::class);
        $client = $repo->findOneBy(['name' => $name]);

        $created = false;

        if (null !== $client && $rotate) {
            // Rotate: drop the old client so a brand new secret is generated.
            $this->em->remove($client);
            $this->em->flush();
            $client = null;
        }

        if (null === $client) {
            $role = $this->resolveAdminRole();
            if (null === $role) {
                $output->writeln(json_encode([
                    'error'   => 'no_admin_role',
                    'message' => 'No admin role found to attach the OAuth2 client to.',
                ], JSON_THROW_ON_ERROR));

                return Command::FAILURE;
            }

            // FOS Client::__construct() already generates random_id + secret.
            $client = new Client();
            $client->setName($name);
            $client->setRedirectUris([$redirectUri]);
            $client->setAllowedGrantTypes([OAuth2::GRANT_TYPE_CLIENT_CREDENTIALS]);
            $client->setRole($role);

            $this->em->persist($client);
            $this->em->flush();

            $created = true;
        }

        $output->writeln(json_encode([
            'clientId'     => $client->getPublicId(),
            'clientSecret' => $client->getSecret(),
            'created'      => $created,
        ], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }

    /**
     * Returns an admin role for the machine client. We reuse the existing
     * admin role rather than creating a dedicated one to keep the permission
     * surface predictable across instances.
     */
    private function resolveAdminRole(): ?Role
    {
        $repo = $this->em->getRepository(Role::class);

        $role = $repo->findOneBy(['isAdmin' => true]);

        return $role instanceof Role ? $role : null;
    }
}
