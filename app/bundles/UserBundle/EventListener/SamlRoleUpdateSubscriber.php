<?php

declare(strict_types=1);

namespace Mautic\UserBundle\EventListener;

use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Model\UserModel;
use Mautic\UserBundle\Security\SAML\Helper;
use Mautic\UserBundle\Security\SAML\User\RoleMapper;
use Mautic\UserBundle\Security\SAML\User\UserMapper;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Synchronises the Mautic role of existing users on each SAML login.
 *
 * New users are handled by UserCreator::createUser() (which already uses
 * RoleMapper). For existing users, LightSaml simply loads them from the DB
 * without touching their role. This subscriber fills that gap.
 *
 * Behaviour rules (defensive on purpose):
 *   - We act only on SAML logins.
 *   - We require a non-empty Keycloak role AND an explicit mapping in
 *     MAUTIC_SAML_ROLE_ID_MAP. Otherwise we do NOT touch the role: this
 *     prevents accidental downgrades when Keycloak omits a role or when the
 *     map is misconfigured.
 *   - We persist only when the role actually changes.
 */
class SamlRoleUpdateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Helper $samlHelper,
        private UserMapper $userMapper,
        private RoleMapper $roleMapper,
        private UserModel $userModel,
        private \Doctrine\ORM\EntityManagerInterface $entityManager,
        private LoggerInterface $mauticLogger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => ['onLoginSuccess', 0],
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if (!$this->samlHelper->isSamlSession()) {
            return;
        }

        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        // No SAML context cached: not a fresh SAML auth (or already consumed
        // by UserCreator for new users). Nothing to do.
        $context = $this->userMapper->getLastContext();
        if (null === $context) {
            return;
        }

        $matchedRole = $context->getMatchedRole();

        // Defensive: refuse to touch the role unless we have an explicit
        // mapping for the SAML role. This avoids silently downgrading
        // existing users when Keycloak does not propagate a role or when the
        // map is empty/misconfigured.
        if (null === $matchedRole || '' === trim($matchedRole)) {
            $this->mauticLogger->debug(
                'SAML role sync: no SAML role for existing user, keeping current role',
                ['username' => $user->getUserIdentifier()]
            );

            return;
        }

        $newRoleId = $this->roleMapper->mapToMauticRoleId($matchedRole);
        if (null === $newRoleId) {
            $this->mauticLogger->warning(
                'SAML role sync: SAML role has no mapping, keeping current role',
                [
                    'username'   => $user->getUserIdentifier(),
                    'samlRole'   => $matchedRole,
                ]
            );

            return;
        }

        if (!$this->roleHasChanged($user->getRole(), $newRoleId)) {
            return;
        }

        $previousRoleId = (int) $user->getRole()?->getId();

        try {
            // find(), pas getReference() : l'id mappé peut ne pas exister
            // dans CETTE instance (base installée avant l'ajout du rôle) —
            // un proxy fantôme casserait le flush en violation de clé
            // étrangère. Id absent : on garde le rôle actuel, comme pour
            // toute map incomplète.
            $newRole = $this->entityManager->find(Role::class, $newRoleId);
            if (null === $newRole) {
                $this->mauticLogger->warning(
                    'SAML role sync: mapped role id does not exist in this instance, keeping current role',
                    [
                        'username'  => $user->getUserIdentifier(),
                        'samlRole'  => $matchedRole,
                        'newRoleId' => $newRoleId,
                    ]
                );

                return;
            }
            $user->setRole($newRole);

            // saveEntity dispatches USER_PRE_SAVE / USER_POST_SAVE so cache is
            // invalidated and audit log is written automatically.
            $this->userModel->saveEntity($user);

            $this->mauticLogger->info(
                'SAML role sync: updated role for existing user',
                [
                    'username'       => $user->getUserIdentifier(),
                    'samlRole'       => $matchedRole,
                    'previousRoleId' => $previousRoleId,
                    'newRoleId'      => $newRoleId,
                ]
            );
        } catch (\Throwable $e) {
            $this->mauticLogger->error(
                'SAML role sync: failed to persist role update',
                [
                    'username' => $user->getUserIdentifier(),
                    'error'    => $e->getMessage(),
                ]
            );
        }
    }

    private function roleHasChanged(?Role $current, int $newRoleId): bool
    {
        if (null === $current) {
            return true;
        }

        return (int) $current->getId() !== $newRoleId;
    }
}

