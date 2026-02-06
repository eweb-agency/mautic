<?php

namespace Mautic\UserBundle\Tests\Security\SAML\User;

use LightSaml\Model\Assertion\Assertion;
use LightSaml\Model\Assertion\Attribute;
use LightSaml\Model\Assertion\AttributeStatement;
use LightSaml\Model\Protocol\Response;
use Mautic\UserBundle\Security\SAML\User\UserMapper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserMapperTest extends TestCase
{
    private UserMapper $mapper;

    /**
     * @var Response|MockObject
     */
    private MockObject $response;

    protected function setUp(): void
    {
        $this->mapper = new UserMapper(
            [
                'email'     => 'EmailAddress',
                'firstname' => 'FirstName',
                'lastname'  => 'LastName',
                'username'  => null,
            ],
            'Group',
            'Role',
            '' // pas de groupe requis pour les tests basiques
        );

        $emailAttribute = $this->createMock(Attribute::class);
        $emailAttribute->method('getFirstAttributeValue')
            ->willReturn('hello@there.com');

        $firstnameAttribute = $this->createMock(Attribute::class);
        $firstnameAttribute->method('getFirstAttributeValue')
            ->willReturn('Joe');

        $lastnameAttribute = $this->createMock(Attribute::class);
        $lastnameAttribute->method('getFirstAttributeValue')
            ->willReturn('Smith');

        $defaultAttribute = $this->createMock(Attribute::class);
        $defaultAttribute->method('getFirstAttributeValue')
            ->willReturn('default');

        $statement = $this->createMock(AttributeStatement::class);
        $statement->method('getFirstAttributeByName')
            ->willReturnCallback(
                fn ($attributeName) => match ($attributeName) {
                    'EmailAddress' => $emailAttribute,
                    'FirstName'    => $firstnameAttribute,
                    'LastName'     => $lastnameAttribute,
                    default        => $defaultAttribute,
                }
            );

        $assertion = $this->createMock(Assertion::class);
        $assertion->method('getAllAttributeStatements')
            ->willReturn([$statement]);

        $this->response = $this->createMock(Response::class);
        $this->response->method('getAllAssertions')
            ->willReturn([$assertion]);
    }

    public function testUserEntityIsPopulatedFromAssertions(): void
    {
        $user = $this->mapper->getUser($this->response);
        $this->assertEquals('hello@there.com', $user->getEmail());
        $this->assertEquals('hello@there.com', $user->getUserIdentifier());
        $this->assertEquals('Joe', $user->getFirstName());
        $this->assertEquals('Smith', $user->getLastName());
    }

    public function testUsernameIsReturned(): void
    {
        $username = $this->mapper->getUsername($this->response);
        $this->assertEquals('hello@there.com', $username);
    }

    /**
     * Test que getUsername lève une exception si le groupe requis est configuré mais absent.
     */
    public function testGetUsernameThrowsExceptionWhenRequiredGroupMissing(): void
    {
        $this->expectException(\Symfony\Component\Security\Core\Exception\BadCredentialsException::class);
        $this->expectExceptionMessage('User missing required SAML group.');

        $mapper = new UserMapper(
            [
                'email'     => 'EmailAddress',
                'firstname' => 'FirstName',
                'lastname'  => 'LastName',
                'username'  => null,
            ],
            'Group',
            'Role',
            'required-org-id'
        );

        $mapper->getUsername($this->response);
    }

    /**
     * Test que getUsername accepte l'authentification quand aucun groupe n'est requis.
     */
    public function testGetUsernameAcceptsWhenNoGroupRequired(): void
    {
        // Le mapper du setUp n'a pas de groupe requis, donc ça doit passer
        $username = $this->mapper->getUsername($this->response);
        $this->assertEquals('hello@there.com', $username);
    }
}
