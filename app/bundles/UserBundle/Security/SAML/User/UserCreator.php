<?php

namespace Mautic\UserBundle\Security\SAML\User;

use Doctrine\ORM\EntityManagerInterface;
use LightSaml\Model\Protocol\Response;
use LightSaml\SpBundle\Security\User\UserCreatorInterface;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Model\UserModel;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserInterface;

class UserCreator implements UserCreatorInterface
{
    private int $defaultRole;

    private array $requiredFields = [
        'username',
        'firstname',
        'lastname',
        'email',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserMapper $userMapper,
        private UserModel $userModel,
        private UserPasswordHasher $hasher,
        $defaultRole,
        private RoleMapper $roleMapper,
    ) {
        $this->defaultRole = (int) $defaultRole;
    }

    /**
     * @return UserInterface|null
     */
    public function createUser(Response $response): User
    {
        $context = $this->userMapper->pullContext($response);

        if (!$context->hasRequiredGroup()) {
            throw new BadCredentialsException('User missing required SAML group.');
        }

        $roleEntity = $this->resolveRole($context->getMatchedRole());

        $user = $this->userMapper->getUser($response);
        $user->setPassword($this->userModel->checkNewPassword($user, $this->hasher, EncryptionHelper::generateKey()));
        $user->setRole($roleEntity);

        $this->validateUser($user);

        $this->userModel->saveEntity($user);

        return $user;
    }

    /**
     * Resolves a Mautic Role entity from a SAML role name using a
     * three-step fallback chain:
     *
     *   1. RoleMapper (env MAUTIC_SAML_ROLE_ID_MAP)  →  lookup by ID
     *   2. Name lookup in DB (backward-compat)        →  lookup by name
     *   3. Default role                               →  configured fallback
     */
    public function resolveRole(?string $samlRole): Role
    {
        if (null !== $samlRole && '' !== trim($samlRole)) {
            $roleId = $this->roleMapper->mapToMauticRoleId($samlRole);
            if (null !== $roleId) {
                /** @var Role $role */
                $role = $this->entityManager->getReference(Role::class, $roleId);

                return $role;
            }

            $role = $this->findRoleByName($samlRole);
            if (null !== $role) {
                return $role;
            }
        }

        return $this->getDefaultRole();
    }

    private function findRoleByName(?string $samlRole): ?Role
    {
        if (null === $samlRole || '' === trim($samlRole)) {
            return null;
        }

        $normalized = strtolower(trim($samlRole));

        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('r')
            ->from(Role::class, 'r')
            ->where('LOWER(r.name) = :name')
            ->setParameter('name', $normalized)
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function getDefaultRole(): Role
    {
        if (empty($this->defaultRole)) {
            throw new BadCredentialsException('User missing role and no default SAML role configured.');
        }

        /** @var Role $defaultRole */
        $defaultRole = $this->entityManager->getReference(Role::class, $this->defaultRole);

        return $defaultRole;
    }

    /**
     * @throws BadCredentialsException
     */
    private function validateUser(User $user): void
    {
        // Validate that the user has all that's required
        foreach ($this->requiredFields as $field) {
            $getter = 'get'.ucfirst($field);

            if (!$user->$getter()) {
                throw new BadCredentialsException('User does not include required fields.');
            }
        }
    }
}
