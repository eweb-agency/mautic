<?php

declare(strict_types=1);

namespace Mautic\UserBundle\Tests\Security\SAML\User;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Model\UserModel;
use Mautic\UserBundle\Security\SAML\User\RoleMapper;
use Mautic\UserBundle\Security\SAML\User\UserCreator;
use Mautic\UserBundle\Security\SAML\User\UserMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasher;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * La chaîne de repli de resolveRole : map d'ids → nom en base → rôle par
 * défaut. Point durci : un id mappé ABSENT de l'instance (base installée
 * avant l'ajout du rôle, faute de frappe dans la map) doit poursuivre la
 * chaîne — jamais renvoyer un proxy fantôme qui casserait le flush en
 * violation de clé étrangère.
 */
class UserCreatorTest extends TestCase
{
    /**
     * @var EntityManagerInterface|MockObject
     */
    private MockObject $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    private function creator(string $roleIdMap, int $defaultRole = 0): UserCreator
    {
        return new UserCreator(
            $this->entityManager,
            $this->createMock(UserMapper::class),
            $this->createMock(UserModel::class),
            // UserPasswordHasher est final (non mockable) ; instance réelle
            // sur factory mockée — resolveRole ne s'en sert pas.
            new UserPasswordHasher($this->createMock(PasswordHasherFactoryInterface::class)),
            $defaultRole,
            new RoleMapper($roleIdMap)
        );
    }

    private function stubNameLookupReturningNothing(): void
    {
        $query = $this->createMock(AbstractQuery::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);
    }

    public function testMappedRoleIsReturnedWhenItExistsInDatabase(): void
    {
        $role = new Role();
        $this->entityManager->method('find')
            ->with(Role::class, 2)
            ->willReturn($role);

        $resolved = $this->creator('owner:2')->resolveRole('owner');

        $this->assertSame($role, $resolved);
    }

    public function testMissingMappedRoleFallsThroughToDefaultInsteadOfPhantomReference(): void
    {
        // La map désigne l'id 2, absent de cette instance ; le repli doit
        // aboutir au rôle par défaut (id 5), sans jamais getReference().
        $defaultRole = new Role();
        $this->entityManager->method('find')
            ->willReturnCallback(
                fn ($class, $id) => 5 === $id ? $defaultRole : null
            );
        $this->entityManager->expects($this->never())->method('getReference');
        $this->stubNameLookupReturningNothing();

        $resolved = $this->creator('owner:2', 5)->resolveRole('owner');

        $this->assertSame($defaultRole, $resolved);
    }

    public function testThrowsCleanlyWhenNothingResolvesAndNoDefaultConfigured(): void
    {
        $this->entityManager->method('find')->willReturn(null);
        $this->stubNameLookupReturningNothing();

        $this->expectException(BadCredentialsException::class);
        $this->expectExceptionMessage('User missing role and no default SAML role configured.');

        $this->creator('owner:2')->resolveRole('owner');
    }

    public function testDeletedDefaultRoleProducesCleanRefusalNotForeignKeyViolation(): void
    {
        // Rôle par défaut configuré (id 9) mais supprimé de la base depuis.
        $this->entityManager->method('find')->willReturn(null);
        $this->entityManager->expects($this->never())->method('getReference');
        $this->stubNameLookupReturningNothing();

        $this->expectException(BadCredentialsException::class);

        $this->creator('', 9)->resolveRole('owner');
    }
}
