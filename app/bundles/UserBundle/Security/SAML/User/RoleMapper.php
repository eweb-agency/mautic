<?php

declare(strict_types=1);

namespace Mautic\UserBundle\Security\SAML\User;

/**
 * Maps SAML role names to Mautic role IDs.
 *
 * Reads the configured role map (parameter %mautic.saml_role_id_map% bound
 * to MAUTIC_SAML_ROLE_ID_MAP) and resolves a SAML role name (e.g. "member")
 * to a Mautic role ID (e.g. 3).
 *
 * Expected format: "superadmin:1,owner:2,admin:2,member:3"
 *
 * The constructor does NOT read environment variables itself; the value
 * must be passed explicitly. This keeps the service deterministic and
 * easy to test.
 */
final class RoleMapper
{
    /** @var array<string, int> Normalized role name → Mautic role ID */
    private array $map;

    public function __construct(?string $roleIdMap = null)
    {
        $this->map = $this->parse((string) $roleIdMap);
    }

    /**
     * Returns the Mautic role ID for a given SAML role name, or null when
     * no mapping is configured for that role.
     */
    public function mapToMauticRoleId(string $samlRole): ?int
    {
        $normalized = strtolower(trim($samlRole));

        return $this->map[$normalized] ?? null;
    }

    public function isEmpty(): bool
    {
        return [] === $this->map;
    }

    /**
     * @return array<string, int>
     */
    private function parse(string $raw): array
    {
        $map = [];

        if ('' === trim($raw)) {
            return $map;
        }

        foreach (explode(',', $raw) as $pair) {
            $parts = explode(':', trim($pair), 2);

            if (2 !== count($parts)) {
                continue;
            }

            $role = strtolower(trim($parts[0]));
            $id   = (int) trim($parts[1]);

            if ('' !== $role && $id > 0) {
                $map[$role] = $id;
            }
        }

        return $map;
    }
}
