<?php

namespace Mautic\UserBundle\Security\SAML\User;

class SamlUserContext
{
    private ?string $requiredGroupId;

    /** @var string[] */
    private array $groups;

    /** @var string[] */
    private array $roles;

    private ?string $matchedGroup = null;

    private ?string $matchedRole = null;

    /**
     * @param string[] $groups
     * @param string[] $roles
     */
    public function __construct(string $requiredGroupId, array $groups, array $roles)
    {
        $this->requiredGroupId = $this->normalizeId($requiredGroupId);
        $this->groups          = $groups;
        $this->roles           = $roles;

        $this->matchedGroup = $this->matchGroup();
        $this->matchedRole  = $this->matchRole();
    }

    public function hasRequiredGroup(): bool
    {
        if (null === $this->requiredGroupId) {
            return true; // no group requirement configured
        }

        return null !== $this->matchedGroup;
    }

    public function getMatchedRole(): ?string
    {
        return $this->matchedRole;
    }

    public function getMatchedGroup(): ?string
    {
        return $this->matchedGroup;
    }

    private function matchGroup(): ?string
    {
        if (null === $this->requiredGroupId) {
            return null;
        }

        foreach ($this->groups as $group) {
            $normalized = $this->normalizeId($group);
            if (null !== $normalized && $normalized === $this->requiredGroupId) {
                return $normalized;
            }
        }

        return null;
    }

    private function matchRole(): ?string
    {
        if (null !== $this->requiredGroupId && null === $this->matchedGroup) {
            return null;
        }

        foreach ($this->roles as $role) {
            $role = trim((string) $role);
            if ('' === $role) {
                continue;
            }

            $segments = explode('/', trim($role, '/'));
            if (count($segments) < 3) {
                continue;
            }

            [$orgId, $rolesKeyword, $roleName] = [$segments[0], $segments[1], $segments[2]];

            if (null !== $this->requiredGroupId && $orgId !== $this->matchedGroup) {
                continue;
            }

            if ('roles' !== strtolower($rolesKeyword)) {
                continue;
            }

            return $roleName;
        }

        return null;
    }

    private function normalizeId(string $value): ?string
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        return trim($value, '/');
    }
}
