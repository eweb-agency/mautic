<?php

declare(strict_types=1);

namespace Mautic\UserBundle\Tests\Security\SAML\User;

use Mautic\UserBundle\Security\SAML\User\RoleMapper;
use PHPUnit\Framework\TestCase;

class RoleMapperTest extends TestCase
{
    public function testMapKnownRoleReturnsCorrectId(): void
    {
        $mapper = new RoleMapper('superadmin:1,owner:2,admin:2,member:3');

        $this->assertSame(1, $mapper->mapToMauticRoleId('superadmin'));
        $this->assertSame(2, $mapper->mapToMauticRoleId('owner'));
        $this->assertSame(2, $mapper->mapToMauticRoleId('admin'));
        $this->assertSame(3, $mapper->mapToMauticRoleId('member'));
    }

    public function testMapIsCaseInsensitive(): void
    {
        $mapper = new RoleMapper('Admin:2,MEMBER:3');

        $this->assertSame(2, $mapper->mapToMauticRoleId('admin'));
        $this->assertSame(2, $mapper->mapToMauticRoleId('ADMIN'));
        $this->assertSame(3, $mapper->mapToMauticRoleId('Member'));
    }

    public function testMapUnknownRoleReturnsNull(): void
    {
        $mapper = new RoleMapper('admin:2');

        $this->assertNull($mapper->mapToMauticRoleId('unknown_role'));
        $this->assertNull($mapper->mapToMauticRoleId(''));
    }

    public function testEmptyMapStringReturnsNull(): void
    {
        $mapper = new RoleMapper('');

        $this->assertNull($mapper->mapToMauticRoleId('admin'));
        $this->assertTrue($mapper->isEmpty());
    }

    public function testNullMapReturnsNull(): void
    {
        $mapper = new RoleMapper(null);

        $this->assertNull($mapper->mapToMauticRoleId('admin'));
        $this->assertTrue($mapper->isEmpty());
    }

    public function testIsEmptyReturnsFalseWhenMapConfigured(): void
    {
        $mapper = new RoleMapper('admin:2');

        $this->assertFalse($mapper->isEmpty());
    }

    public function testMapWithExtraSpacesIsParsedCorrectly(): void
    {
        $mapper = new RoleMapper(' admin : 2 , member : 3 ');

        $this->assertSame(2, $mapper->mapToMauticRoleId('admin'));
        $this->assertSame(3, $mapper->mapToMauticRoleId('member'));
    }

    public function testMalformedEntriesAreSkipped(): void
    {
        // "nocolon" has no colon separator, "admin:0" has an invalid ID (0)
        $mapper = new RoleMapper('nocolon,admin:0,member:3');

        $this->assertNull($mapper->mapToMauticRoleId('nocolon'));
        $this->assertNull($mapper->mapToMauticRoleId('admin'));
        $this->assertSame(3, $mapper->mapToMauticRoleId('member'));
    }
}
