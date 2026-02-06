<?php

namespace Mautic\UserBundle\Tests\Security\SAML\User;

use Mautic\UserBundle\Security\SAML\User\SamlUserContext;
use PHPUnit\Framework\TestCase;

class SamlUserContextTest extends TestCase
{
    /**
     * Test que le contexte accepte une authentification quand le groupe requis est absent
     * (config optionnelle).
     */
    public function testHasRequiredGroupReturnsTrueWhenGroupNotConfigured(): void
    {
        $context = new SamlUserContext('', ['user-group'], ['org-id/roles/member']);

        $this->assertTrue($context->hasRequiredGroup());
        $this->assertNull($context->getMatchedGroup());
    }

    /**
     * Test que le contexte refuse quand le groupe requis est configuré mais absent.
     */
    public function testHasRequiredGroupReturnsFalseWhenGroupMissing(): void
    {
        $context = new SamlUserContext('required-org-id', ['other-group'], ['required-org-id/roles/member']);

        $this->assertFalse($context->hasRequiredGroup());
    }

    /**
     * Test que le contexte accepte quand le groupe requis est présent.
     */
    public function testHasRequiredGroupReturnsTrueWhenGroupPresent(): void
    {
        $context = new SamlUserContext('required-org-id', ['required-org-id', 'other-group'], ['required-org-id/roles/admin']);

        $this->assertTrue($context->hasRequiredGroup());
        $this->assertEquals('required-org-id', $context->getMatchedGroup());
    }

    /**
     * Test que le contexte gère les slashes dans les IDs de groupe.
     */
    public function testHasRequiredGroupHandlesSlashesInGroupId(): void
    {
        $context = new SamlUserContext(
            '/required-org-id/',
            ['/required-org-id', 'other-group'],
            ['/required-org-id/roles/member']
        );

        $this->assertTrue($context->hasRequiredGroup());
        $this->assertEquals('required-org-id', $context->getMatchedGroup());
    }

    /**
     * Test que le rôle est extrait correctement du format /org_id/roles/role_name.
     */
    public function testGetMatchedRoleExtractsRoleCorrectly(): void
    {
        $context = new SamlUserContext(
            'org-id',
            ['org-id'],
            ['org-id/roles/admin', 'other/roles/user']
        );

        $this->assertEquals('admin', $context->getMatchedRole());
    }

    /**
     * Test que le rôle est null quand le groupe requis n'est pas présent.
     */
    public function testGetMatchedRoleReturnsNullWhenGroupMissing(): void
    {
        $context = new SamlUserContext(
            'required-org-id',
            ['other-group'],
            ['required-org-id/roles/member']
        );

        $this->assertNull($context->getMatchedRole());
    }

    /**
     * Test que le rôle est null quand aucun rôle ne correspond au groupe requis.
     */
    public function testGetMatchedRoleReturnsNullWhenRoleNotMatching(): void
    {
        $context = new SamlUserContext(
            'org-id',
            ['org-id'],
            ['other-org/roles/admin']
        );

        $this->assertNull($context->getMatchedRole());
    }

    /**
     * Test que le rôle ignore les entrées sans le format correct.
     */
    public function testGetMatchedRoleIgnoresInvalidFormats(): void
    {
        $context = new SamlUserContext(
            'org-id',
            ['org-id'],
            ['invalid-format', 'org-id/noproles/member', 'org-id/roles/']
        );

        $this->assertNull($context->getMatchedRole());
    }

    /**
     * Test avec aucun groupe requis et rôles présents.
     */
    public function testGetMatchedRoleReturnsFirstRoleWhenNoGroupRequired(): void
    {
        $context = new SamlUserContext(
            '',
            [],
            ['/some-org/roles/owner', '/other-org/roles/member']
        );

        $this->assertTrue($context->hasRequiredGroup()); // accepté
        $this->assertEquals('owner', $context->getMatchedRole());
    }
}
