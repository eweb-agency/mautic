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

        $roleEntity = $this->findRoleForSaml($context->getMatchedRole());
        if (null === $roleEntity) {
            $roleEntity = $this->getDefaultRole();
        }

        $user = $this->userMapper->getUser($response);
        $user->setPassword($this->userModel->checkNewPassword($user, $this->hasher, EncryptionHelper::generateKey()));
        $user->setRole($roleEntity);

        $this->validateUser($user);

        $this->userModel->saveEntity($user);

        return $user;
    }

    private function findRoleForSaml(?string $samlRole): ?Role
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
