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
        // Groupes/rôles : lus via getAllAttributes (aucun ici).
        $statement->method('getAllAttributes')
            ->willReturn([]);

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

    /**
     * Keycloak avec « Single Group Attribute » désactivé émet UN élément
     * <Attribute Name="Group"> PAR groupe : le groupe requis peut arriver
     * dans n'importe quel élément, pas seulement le premier. Ne lire que le
     * premier faisait dépendre l'accès de l'ordre d'émission (le bug qui
     * refusait toute org dont la racine n'arrivait pas en tête).
     */
    public function testEveryGroupAttributeElementIsRead(): void
    {
        $firstGroup = $this->createMock(Attribute::class);
        $firstGroup->method('getName')->willReturn('Group');
        $firstGroup->method('getAllAttributeValues')->willReturn(['/other-org-id']);

        $secondGroup = $this->createMock(Attribute::class);
        $secondGroup->method('getName')->willReturn('Group');
        $secondGroup->method('getAllAttributeValues')->willReturn(['/required-org-id']);

        $unrelated = $this->createMock(Attribute::class);
        $unrelated->method('getName')->willReturn('EmailAddress');

        $statement = $this->createMock(AttributeStatement::class);
        $statement->method('getAllAttributes')
            ->willReturn([$firstGroup, $unrelated, $secondGroup]);
        $statement->method('getFirstAttributeByName')->willReturn(null);

        $assertion = $this->createMock(Assertion::class);
        $assertion->method('getAllAttributeStatements')->willReturn([$statement]);

        $response = $this->createMock(Response::class);
        $response->method('getAllAssertions')->willReturn([$assertion]);

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

        // Le groupe requis est porté par le SECOND élément Attribute : il
        // doit être vu, donc aucune BadCredentialsException.
        $mapper->getUser($response);
        $context = $mapper->pullContext($response);

        $this->assertTrue($context->hasRequiredGroup());
        $this->assertSame('required-org-id', $context->getMatchedGroup());
    }
}
