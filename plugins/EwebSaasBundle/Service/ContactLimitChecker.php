<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\EwebSaasBundle\Exception\ContactLimitExceededException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class ContactLimitChecker
{
    private const CACHE_KEY = 'eweb_saas_contact_count';

    private const CACHE_TTL = 300; // 5 minutes

    private readonly int $maxLimit;

    private readonly CacheInterface $cache;

    private readonly string $leadsTable;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        ?CacheInterface $cache = null,
    ) {
        $envValue         = $_ENV['MAUTIC_CONTACT_MAX_LIMIT'] ?? $_SERVER['MAUTIC_CONTACT_MAX_LIMIT'] ?? getenv('MAUTIC_CONTACT_MAX_LIMIT');
        $this->maxLimit   = (int) ($envValue ?: 0);
        $this->cache      = $cache ?? new FilesystemAdapter('eweb_saas_limit', self::CACHE_TTL);
        $this->leadsTable = (defined('MAUTIC_TABLE_PREFIX') ? MAUTIC_TABLE_PREFIX : '').'leads';

        $this->logger->debug('EwebSaasBundle: Initialized with MAUTIC_CONTACT_MAX_LIMIT={limit}', [
            'limit' => $this->maxLimit,
        ]);
    }

    /**
     * Returns the configured max limit (0 = no limit).
     */
    public function getMaxLimit(): int
    {
        return $this->maxLimit;
    }

    /**
     * Returns true if a contact limit is configured and active.
     */
    public function isLimitEnabled(): bool
    {
        return $this->maxLimit > 0;
    }

    /**
     * Returns the current number of IDENTIFIED contacts (cached).
     *
     * Product decision (owner, 2026-07-20): the quota counts identified
     * contacts only. Mautic writes a `leads` row for every anonymous tracked
     * visitor, so an unfiltered COUNT(*) let a tenant with a tracking pixel
     * exhaust their plan on traffic alone — and get their next REAL contact
     * blocked. `date_identified` is set by Lead::checkDateIdentified() at the
     * exact moment a lead stops being anonymous; the same predicate already
     * backs the `identifiedLast30d` metric in StatsAggregator.
     *
     * The enforcement gate (DoctrineContactLimitSubscriber) follows the SAME
     * definition via Lead::isAnonymous() — count and gate must never diverge.
     */
    public function getCurrentContactCount(): int
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): int {
            $item->expiresAfter(self::CACHE_TTL);

            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.$this->leadsTable
                .' WHERE date_identified IS NOT NULL'
            );

            $this->logger->debug('EwebSaasBundle: Refreshed contact count from DB', [
                'count' => $count,
            ]);

            return $count;
        });
    }

    /**
     * Invalidates the cached contact count so the next call re-queries the DB.
     */
    public function invalidateCache(): void
    {
        $this->cache->delete(self::CACHE_KEY);
        $this->logger->debug('EwebSaasBundle: Contact count cache invalidated');
    }

    /**
     * Checks whether a new contact can be created.
     * Returns true if allowed, false if the limit is reached.
     */
    public function canCreateContact(): bool
    {
        if (!$this->isLimitEnabled()) {
            return true;
        }

        $currentCount = $this->getCurrentContactCount();

        return $currentCount < $this->maxLimit;
    }

    /**
     * Asserts that a new contact can be created. Throws if not.
     *
     * @throws ContactLimitExceededException
     */
    public function assertCanCreateContact(): void
    {
        if (!$this->isLimitEnabled()) {
            $this->logger->debug('EwebSaasBundle: No limit configured, allowing contact creation');

            return;
        }

        $currentCount = $this->getCurrentContactCount();

        if ($currentCount >= $this->maxLimit) {
            $this->logger->warning('EwebSaasBundle: Contact limit reached, blocking creation', [
                'currentCount' => $currentCount,
                'maxLimit'     => $this->maxLimit,
            ]);

            throw new ContactLimitExceededException($currentCount, $this->maxLimit);
        }

        $this->logger->debug('EwebSaasBundle: Contact creation allowed', [
            'currentCount' => $currentCount,
            'maxLimit'     => $this->maxLimit,
        ]);
    }
}
