<?php

declare(strict_types=1);

namespace Mautic\UserBundle\Tests\Functional\Controller;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\Permission;
use Mautic\UserBundle\Entity\Role;
use Mautic\UserBundle\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

class RoleControllerFunctionalTest extends MauticMysqlTestCase
{
    public function testNewRoleAction(): void
    {
        $crawler    = $this->client->request(Request::METHOD_GET, '/s/roles/new');
        $saveButton = $crawler->selectButton('role[buttons][apply]');

        $name = 'Test Role';
        $desc = 'Role Description';

        $form = $saveButton->form();
        $form['role[name]']->setValue($name);
        $form['role[description]']->setValue($desc);

        $this->client->submit($form);
        $this->assertTrue($this->client->getResponse()->isOk(), $this->client->getResponse()->getContent());

        $this->assertStringContainsString($name, $this->client->getResponse()->getContent());
        $this->assertStringContainsString($desc, $this->client->getResponse()->getContent());
    }

    public function testEditRoleAction(): void
    {
        $role = new Role();
        $role->setName('Test Role');
        $role->setDescription('The Description');

        $this->em->persist($role);
        $this->em->flush();

        $crawler    = $this->client->request(Request::METHOD_GET, '/s/roles/edit/'.$role->getId());
        $saveButton = $crawler->selectButton('role[buttons][save]');

        $updatedName = 'Test Role Updated';

        $form = $saveButton->form();
        $form['role[name]']->setValue($updatedName);

        $this->client->submit($form);
        $this->assertTrue($this->client->getResponse()->isOk());

        $this->assertStringContainsString($updatedName, $this->client->getResponse()->getContent());
    }

    public function testIndexActionCanSortByUserCount(): void
    {
        $uniquePrefix = 'TestRole'.uniqid();

        $role1 = new Role();
        $role1->setName($uniquePrefix.' 1');
        $this->em->persist($role1);

        $role2 = new Role();
        $role2->setName($uniquePrefix.' 2');
        $this->em->persist($role2);

        $role3 = new Role();
        $role3->setName($uniquePrefix.' 3');
        $this->em->persist($role3);

        $this->em->flush();

        $this->em->persist($this->createUser('user1', $role1));
        $this->em->persist($this->createUser('user2', $role1));
        $this->em->persist($this->createUser('user3', $role2));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/s/roles?tmpl=list&search='.$uniquePrefix.'&orderby=user_count&orderbydir=DESC');
        $rows    = $crawler->filter('#roleTable tbody tr');

        $this->assertSame($uniquePrefix.' 1', trim($rows->eq(0)->filter('td')->eq(1)->text()));
        $this->assertSame($uniquePrefix.' 2', trim($rows->eq(1)->filter('td')->eq(1)->text()));
        $this->assertSame($uniquePrefix.' 3', trim($rows->eq(2)->filter('td')->eq(1)->text()));

        $crawler = $this->client->request('GET', '/s/roles?tmpl=list&search='.$uniquePrefix.'&orderby=user_count&orderbydir=ASC');
        $rows    = $crawler->filter('#roleTable tbody tr');

        $this->assertSame($uniquePrefix.' 3', trim($rows->eq(0)->filter('td')->eq(1)->text()));
        $this->assertSame($uniquePrefix.' 2', trim($rows->eq(1)->filter('td')->eq(1)->text()));
        $this->assertSame($uniquePrefix.' 1', trim($rows->eq(2)->filter('td')->eq(1)->text()));
    }

    public function testNonAdminCannotCreateAnAdminRole(): void
    {
        $this->loginRoleManager();

        $crawler = $this->client->request(Request::METHOD_GET, '/s/roles/new');
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[name="role[isAdmin]"]'), 'The full access switch is hidden from a non-admin.');

        // A forged submission must not grant full access either.
        $form                      = $crawler->selectButton('role[buttons][apply]')->form();
        $values                    = $form->getPhpValues();
        $values['role']['name']    = 'Forged admin role';
        $values['role']['isAdmin'] = '1';
        $this->client->request(Request::METHOD_POST, $form->getUri(), $values);

        $this->em->clear();
        $role = $this->em->getRepository(Role::class)->findOneBy(['name' => 'Forged admin role']);
        $this->assertTrue(null === $role || !$role->isAdmin());
    }

    public function testNonAdminCannotPromoteOwnRole(): void
    {
        $role = $this->loginRoleManager()->getRole();

        $crawler = $this->client->request(Request::METHOD_GET, '/s/roles/edit/'.$role->getId());
        $this->assertResponseIsSuccessful();

        $form                      = $crawler->selectButton('role[buttons][apply]')->form();
        $values                    = $form->getPhpValues();
        $values['role']['isAdmin'] = '1';
        $this->client->request(Request::METHOD_POST, $form->getUri(), $values);

        $this->em->clear();
        $this->assertFalse($this->em->getRepository(Role::class)->find($role->getId())->isAdmin());
    }

    public function testNonAdminCannotEditOrDeleteAnAdminRole(): void
    {
        $adminRole = $this->em->getRepository(Role::class)->findOneBy(['isAdmin' => true]);
        $this->assertNotNull($adminRole);
        $this->loginRoleManager();

        $this->client->request(Request::METHOD_GET, '/s/roles/edit/'.$adminRole->getId());
        $this->assertResponseStatusCodeSame(403);

        $this->client->request(Request::METHOD_POST, '/s/roles/delete/'.$adminRole->getId());
        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $this->assertNotNull($this->em->getRepository(Role::class)->find($adminRole->getId()));
    }

    /**
     * A non-admin user who may manage roles (user:roles full), logged in.
     */
    private function loginRoleManager(): User
    {
        $role = new Role();
        $role->setName('Role manager');
        $role->setIsAdmin(false);
        $this->em->persist($role);

        $permission = new Permission();
        $permission->setBundle('user');
        $permission->setName('roles');
        $permission->setBitwise(1024);
        $role->addPermission($permission);
        $this->em->persist($permission);

        $user = $this->createUser('role-manager', $role);
        /** @var PasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get('security.password_hasher_factory')->getPasswordHasher($user);
        $user->setPassword($hasher->hash('Maut1cR0cks!'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->request(Request::METHOD_GET, '/s/logout');
        $this->loginUser($user);
        $this->client->setServerParameter('PHP_AUTH_USER', $user->getUserIdentifier());
        $this->client->setServerParameter('PHP_AUTH_PW', 'Maut1cR0cks!');

        return $user;
    }

    private function createUser(string $username, Role $role): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username.'@example.com');
        $user->setFirstName('First');
        $user->setLastName('Last');
        $user->setPassword('password');
        $user->setRole($role);

        return $user;
    }
}
