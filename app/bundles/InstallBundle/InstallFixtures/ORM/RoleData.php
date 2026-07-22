<?php

namespace Mautic\InstallBundle\InstallFixtures\ORM;

use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Mautic\UserBundle\Entity\Permission;
use Mautic\UserBundle\Entity\Role;
use Symfony\Contracts\Translation\TranslatorInterface;

class RoleData extends AbstractFixture implements OrderedFixtureInterface, FixtureGroupInterface
{
    /**
     * Permissions for the "Owner" role.
     * Bundle => [name => bitwise].
     */
    private const OWNER_PERMISSIONS = [
        'core'              => ['themes' => 1024],
        'notification'      => ['categories' => 1024, 'notifications' => 1024, 'mobile_notifications' => 1024],
        'project'           => ['project' => 1024],
        'webhook'           => ['categories' => 1024, 'webhooks' => 1024],
        'category'          => ['categories' => 1024],
        'focus'             => ['categories' => 1024, 'items' => 1024],
        'asset'             => ['categories' => 1024, 'assets' => 1024],
        'sms'               => ['categories' => 1024, 'smses' => 1024],
        'channel'           => ['categories' => 1024, 'messages' => 1024],
        'report'            => ['reports' => 1024, 'export' => 1024],
        'stage'             => ['categories' => 1024, 'stages' => 1024],
        'lead'              => ['leads' => 1024, 'lists' => 1024, 'fields' => 1024, 'export' => 1024, 'imports' => 1024],
        'form'              => ['categories' => 1024, 'forms' => 1024, 'export' => 1024],
        'tagManager'        => ['tagManager' => 1024],
        'email'             => ['categories' => 1024, 'emails' => 1024],
        'page'              => ['categories' => 1024, 'pages' => 1024, 'preference_center' => 1024],
        'campaign'          => ['categories' => 1024, 'campaigns' => 1024, 'export' => 1024, 'imports' => 1024],
        'dynamiccontent'    => ['categories' => 1024, 'dynamiccontents' => 1024],
        'point'             => ['categories' => 1024, 'points' => 1024, 'triggers' => 1024, 'groups' => 1024],
        'mauticSocial'      => ['categories' => 1024, 'monitoring' => 1024, 'tweets' => 1024],
        'user'              => ['users' => 20, 'roles' => 1024, 'profile' => 1024],
    ];

    /**
     * Permissions for the "Member" role.
     * Bundle => [name => bitwise].
     */
    private const MEMBER_PERMISSIONS = [
        'core'              => ['themes' => 1024],
        'notification'      => ['categories' => 1024, 'notifications' => 1024, 'mobile_notifications' => 1024],
        'project'           => ['project' => 1024],
        'category'          => ['categories' => 1024],
        'asset'             => ['categories' => 1024, 'assets' => 1024],
        'sms'               => ['categories' => 1024, 'smses' => 1024],
        'channel'           => ['categories' => 1024, 'messages' => 1024],
        'report'            => ['reports' => 1024, 'export' => 1024],
        'stage'             => ['categories' => 1024, 'stages' => 1024],
        'lead'              => ['leads' => 1024, 'lists' => 1024, 'fields' => 1024, 'export' => 1024, 'imports' => 1024],
        'form'              => ['categories' => 1024, 'forms' => 1024, 'export' => 1024],
        'tagManager'        => ['tagManager' => 1024],
        'email'             => ['categories' => 1024, 'emails' => 1024],
        'page'              => ['categories' => 1024, 'pages' => 1024, 'preference_center' => 1024],
        'campaign'          => ['categories' => 1024, 'campaigns' => 1024, 'imports' => 1024],
        'dynamiccontent'    => ['categories' => 1024, 'dynamiccontents' => 1024],
        'point'             => ['categories' => 1024, 'points' => 1024, 'triggers' => 1024, 'groups' => 1024],
        'mauticSocial'      => ['categories' => 1024, 'monitoring' => 1024, 'tweets' => 1024],
        'user'              => ['roles' => 4],
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public static function getGroups(): array
    {
        return ['group_install', 'group_mautic_install_data'];
    }

    public function load(ObjectManager $manager): void
    {
        if ($this->hasReference('admin-role')) {
            return;
        }

        // ── Administrator (admin) ─────────────────────────────────────────
        $adminRole = new Role();
        $adminRole->setName($this->translator->trans('mautic.user.role.admin.name', [], 'fixtures'));
        $adminRole->setDescription($this->translator->trans('mautic.user.role.admin.description', [], 'fixtures'));
        $adminRole->setIsAdmin(1);
        $manager->persist($adminRole);
        $manager->flush();

        $this->addReference('admin-role', $adminRole);

        // ── Owner ─────────────────────────────────────────────────────────
        $ownerRole = new Role();
        $ownerRole->setName('Owner');
        $ownerRole->setIsAdmin(0);
        $manager->persist($ownerRole);

        foreach (self::OWNER_PERMISSIONS as $bundle => $names) {
            foreach ($names as $name => $bitwise) {
                $permission = new Permission();
                $permission->setBundle($bundle);
                $permission->setName($name);
                $permission->setBitwise($bitwise);
                $permission->setRole($ownerRole);
                $manager->persist($permission);
            }
        }

        // ── Member ────────────────────────────────────────────────────────
        $memberRole = new Role();
        $memberRole->setName('Member');
        $memberRole->setIsAdmin(0);
        $manager->persist($memberRole);

        foreach (self::MEMBER_PERMISSIONS as $bundle => $names) {
            foreach ($names as $name => $bitwise) {
                $permission = new Permission();
                $permission->setBundle($bundle);
                $permission->setName($name);
                $permission->setBitwise($bitwise);
                $permission->setRole($memberRole);
                $manager->persist($permission);
            }
        }

        $manager->flush();
    }

    public function getOrder()
    {
        return 1;
    }
}
