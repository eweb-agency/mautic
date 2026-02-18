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
        error_log('=== SAML AUTH DEBUG START ===');
        $context = $this->resolveContext($response);

        error_log('SAML: requiredGroupId = '.var_export($this->requiredGroupId, true));
        error_log('SAML: hasRequiredGroup() = '.var_export($context->hasRequiredGroup(), true));
        error_log('SAML: getMatchedGroup() = '.var_export($context->getMatchedGroup(), true));

        if (null !== $context->getMatchedGroup() || $context->hasRequiredGroup()) {
            error_log('SAML: Group check PASSED');
        } else {
            error_log('SAML: Group check FAILED - throwing BadCredentialsException');
            error_log('=== SAML AUTH DEBUG END ===');
            throw new BadCredentialsException('User missing required SAML group.');
        }

        $user = $this->getUser($response);
        error_log('SAML: User identifier = '.$user->getUserIdentifier());
        error_log('=== SAML AUTH DEBUG END ===');

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
            $context             = $this->lastContext;
            $this->lastContext   = null;

            return $context;
        }

        return $this->resolveContext($response);
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

        error_log('SAML buildContext: groupAttribute = '.$this->groupAttribute);
        error_log('SAML buildContext: roleAttribute = '.$this->roleAttribute);
        error_log('SAML buildContext: groups found = '.json_encode($groups));
        error_log('SAML buildContext: roles found = '.json_encode($roles));

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

        // DEBUG: Log attribute mapping config
        error_log('SAML extractAttributes: Looking for attributes: '.json_encode($this->attributes));

        // DEBUG: Log all available attributes in the assertion
        foreach ($assertion->getAllAttributeStatements() as $attributeStatement) {
            $allAttrs = [];
            foreach ($attributeStatement->getAllAttributes() as $attr) {
                $allAttrs[$attr->getName()] = $attr->getFirstAttributeValue();
            }
            error_log('SAML extractAttributes: Available attributes in assertion: '.json_encode($allAttrs));
        }

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

        error_log('SAML extractAttributes: Extracted attributes: '.json_encode($attributes));

        return $attributes;
    }

    private function extractMultiValueAttributes(Assertion $assertion, string $attributeName): array
    {
        $values = [];

        foreach ($assertion->getAllAttributeStatements() as $attributeStatement) {
            $attribute = $attributeStatement->getFirstAttributeByName($attributeName);
            if (!$attribute) {
                continue;
            }

            foreach ((array) $attribute->getAllAttributeValues() as $value) {
                if (is_string($value) && '' !== trim($value)) {
                    $values[] = $value;
                }
            }
        }

        return $values;
    }
}
