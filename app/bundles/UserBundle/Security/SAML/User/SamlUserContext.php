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

        // Primary source: configured role attribute (legacy behavior).
        $fromRoles = $this->matchRoleFromCandidates($this->roles);
        if (null !== $fromRoles) {
            return $fromRoles;
        }

        // Fallback: some IdPs (Keycloak setup here) embed app roles in Group.
        return $this->matchRoleFromCandidates($this->groups);
    }

    /**
     * @param string[] $candidates
     */
    private function matchRoleFromCandidates(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $roleName = $this->extractRoleName($candidate);
            if (null !== $roleName) {
                return $roleName;
            }
        }

        return null;
    }

    private function extractRoleName(string $value): ?string
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($value, '/')), static fn (string $segment): bool => '' !== trim($segment)));
        if (count($segments) < 3) {
            return null;
        }

        $orgId = $segments[0];
        if (null !== $this->requiredGroupId && $orgId !== $this->matchedGroup) {
            return null;
        }

        // Format A: /{orgId}/roles/{roleName}
        if ('roles' === strtolower($segments[1])) {
            $roleName = $segments[2] ?? '';

            return '' !== trim($roleName) ? $roleName : null;
        }

        // Format B: /{orgId}/apps/mautic/roles/{roleName}
        // Strict: only the "mautic" app is accepted to avoid privilege
        // escalation via groups belonging to other apps.
        if (
            count($segments) >= 5
            && 'apps' === strtolower($segments[1])
            && 'mautic' === strtolower($segments[2])
            && 'roles' === strtolower($segments[3])
        ) {
            $roleName = $segments[4] ?? '';

            return '' !== trim($roleName) ? $roleName : null;
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
