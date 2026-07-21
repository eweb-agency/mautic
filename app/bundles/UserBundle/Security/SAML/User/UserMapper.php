<?php

namespace Mautic\UserBundle\Security\SAML\User;

use LightSaml\Model\Assertion\Assertion;
use LightSaml\Model\Protocol\Response;
use LightSaml\SpBundle\Security\User\UsernameMapperInterface;
use Mautic\UserBundle\Entity\User;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;

class UserMapper implements UsernameMapperInterface
{
    private ?SamlUserContext $lastContext = null;

    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private array $attributes,
        private string $groupAttribute,
        private string $roleAttribute,
        private string $requiredGroupId,
    ) {
    }

    public function getUsername(Response $response): ?string
    {
        $context = $this->resolveContext($response);

        if (null !== $context->getMatchedGroup() || $context->hasRequiredGroup()) {
            // ok
        } else {
            throw new BadCredentialsException('User missing required SAML group.');
        }

        $user = $this->getUser($response);

        return $user->getUserIdentifier();
    }

    public function getUser(Response $response): User
    {
        // Build and cache context, but do not fail here if the required group is missing.
        $this->resolveContext($response);

        $user = new User();

        foreach ($response->getAllAssertions() as $assertion) {
            $this->setValuesFromAssertion($assertion, $user);
        }

        return $user;
    }

    public function pullContext(Response $response): SamlUserContext
    {
        if (null !== $this->lastContext) {
            $context           = $this->lastContext;
            $this->lastContext = null;

            return $context;
        }

        return $this->resolveContext($response);
    }

    /**
     * Returns the last resolved context without consuming it.
     *
     * Useful for subscribers that need to read the matched Keycloak role after
     * authentication (e.g. to sync an existing user's role on login). Unlike
     * pullContext(), this does NOT clear the cached context.
     */
    public function getLastContext(): ?SamlUserContext
    {
        return $this->lastContext;
    }

    private function resolveContext(Response $response): SamlUserContext
    {
        $context           = $this->buildContext($response);
        $this->lastContext = $context;

        return $context;
    }

    private function buildContext(Response $response): SamlUserContext
    {
        $groups = [];
        $roles  = [];

        foreach ($response->getAllAssertions() as $assertion) {
            $groups = array_merge($groups, $this->extractMultiValueAttributes($assertion, $this->groupAttribute));
            $roles  = array_merge($roles, $this->extractMultiValueAttributes($assertion, $this->roleAttribute));
        }

        return new SamlUserContext($this->requiredGroupId, $groups, $roles);
    }

    private function setValuesFromAssertion(Assertion $assertion, User $user): void
    {
        $attributes = $this->extractAttributes($assertion);

        // use email as the user by default
        if (isset($attributes['email'])) {
            $user->setEmail($attributes['email']);
            $user->setUsername($attributes['email']);
        }

        if (isset($attributes['username']) && !empty($attributes['username'])) {
            $user->setUsername($attributes['username']);
        }

        if (isset($attributes['firstname'])) {
            $user->setFirstname($attributes['firstname']);
        }

        if (isset($attributes['lastname'])) {
            $user->setLastName($attributes['lastname']);
        }
    }

    private function extractAttributes(Assertion $assertion): array
    {
        $attributes = [];

        foreach ($this->attributes as $key => $attributeName) {
            if (!$attributeName) {
                continue;
            }

            foreach ($assertion->getAllAttributeStatements() as $attributeStatement) {
                $attribute = $attributeStatement->getFirstAttributeByName($attributeName);
                if ($attribute && $attribute->getFirstAttributeValue()) {
                    $attributes[$key] = $attribute->getFirstAttributeValue();
                }
            }
        }

        return $attributes;
    }

    private function extractMultiValueAttributes(Assertion $assertion, string $attributeName): array
    {
        $values = [];

        foreach ($assertion->getAllAttributeStatements() as $attributeStatement) {
            // Iterate EVERY attribute carrying this name: Keycloak's group
            // mapper with "Single Group Attribute" off emits one <Attribute>
            // element per group, so reading only the first element would drop
            // every other membership and make access depend on emission order.
            foreach ($attributeStatement->getAllAttributes() as $attribute) {
                if ($attribute->getName() !== $attributeName) {
                    continue;
                }

                foreach ((array) $attribute->getAllAttributeValues() as $value) {
                    if (is_string($value) && '' !== trim($value)) {
                        $values[] = $value;
                    }
                }
            }
        }

        return $values;
    }
}
