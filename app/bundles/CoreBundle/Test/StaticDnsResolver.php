<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Test;

/**
 * DNS resolver stub for the test environment: resolves any hostname to a fixed
 * public IP so no test ever depends on the runner's DNS being available.
 */
class StaticDnsResolver
{
    /**
     * @return string[]
     */
    public static function resolve(string $hostname): array
    {
        // TEST-NET-2 (RFC 5737): documentation range, public as far as private-address checks go
        return ['198.51.100.1'];
    }
}
