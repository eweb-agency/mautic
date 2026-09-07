<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * États RÉELS des intégrations d'une instance — la pilule d'état de la page
 * Intégrations du portail (v14, lot 3 : « est-ce branché ou pas ? »).
 *
 * Lecture directe par DBAL, comme StatsAggregator : aucune dépendance aux
 * dépôts du moteur, qui bougent entre versions. Trois sources :
 *  - `webhooks` : nombre de webhooks publiés ;
 *  - `plugin_integration_settings` : un connecteur est « activé » quand
 *    is_published vaut 1, « autorisé » quand des clés API y sont posées ;
 *  - `oauth2_accesstokens` / `oauth2_refreshtokens` : la boutique WooCommerce
 *    est « connectée » dès qu'un jeton a été délivré au client OAuth nommé
 *    `woocommerce` (le bouton « Se connecter » de l'extension le demande).
 *
 * Chaque source est lue à part et tolère l'échec (table absente, moteur
 * ancien) : une source en panne rend un état INCONNU (null / vide), jamais
 * un faux « débranché », et n'entraîne pas les autres.
 */
final class IntegrationStateReader
{
    /** Client OAuth par instance créé par le provisionneur pour l'extension. */
    public const WOOCOMMERCE_OAUTH_CLIENT = 'woocommerce';

    /** Connecteurs du moteur exposés au portail (valeurs de la colonne `name`). */
    public const PLUGINS = ['Zapier', 'Hubspot', 'Zoho', 'Salesforce', 'Dynamics', 'Sugarcrm', 'Vtiger', 'Connectwise'];

    private readonly string $prefix;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        $this->prefix = defined('MAUTIC_TABLE_PREFIX') ? (string) (MAUTIC_TABLE_PREFIX ?? '') : '';
    }

    /**
     * @return array{
     *     webhooks: array{published: int|null},
     *     plugins: array<string, array{published: bool, authorized: bool}>,
     *     woocommerce: array{connected: bool|null},
     *     generatedAt: string
     * }
     */
    public function getStates(): array
    {
        return [
            'webhooks'    => ['published' => $this->countPublishedWebhooks()],
            'plugins'     => $this->readPlugins(),
            'woocommerce' => ['connected' => $this->isWooCommerceConnected()],
            'generatedAt' => gmdate('c'),
        ];
    }

    private function countPublishedWebhooks(): ?int
    {
        try {
            return (int) $this->connection->fetchOne(
                sprintf('SELECT COUNT(*) FROM %swebhooks WHERE is_published = 1', $this->prefix)
            );
        } catch (\Throwable $e) {
            $this->logger->warning('EwebSaasBundle: webhooks state unreadable: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, array{published: bool, authorized: bool}>
     */
    private function readPlugins(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::PLUGINS), '?'));

        try {
            $rows = $this->connection->fetchAllAssociative(
                sprintf(
                    'SELECT name, is_published, api_keys FROM %splugin_integration_settings WHERE name IN (%s)',
                    $this->prefix,
                    $placeholders
                ),
                self::PLUGINS
            );
        } catch (\Throwable $e) {
            $this->logger->warning('EwebSaasBundle: plugin states unreadable: {msg}', ['msg' => $e->getMessage()]);

            return [];
        }

        $states = [];
        foreach ($rows as $row) {
            $key          = strtolower((string) ($row['name'] ?? ''));
            $states[$key] = [
                'published'  => (bool) ($row['is_published'] ?? false),
                'authorized' => self::hasApiKeys($row['api_keys'] ?? null),
            ];
        }

        return $states;
    }

    /**
     * Les clés API sont stockées sérialisées puis chiffrées : on ne les lit
     * pas, on constate seulement qu'il y a quelque chose (un tableau vide
     * sérialisé compte pour rien).
     */
    private static function hasApiKeys(mixed $apiKeys): bool
    {
        if (!is_string($apiKeys)) {
            return false;
        }
        $trimmed = trim($apiKeys);

        return '' !== $trimmed && 'a:0:{}' !== $trimmed;
    }

    private function isWooCommerceConnected(): ?bool
    {
        try {
            $sql           = 'SELECT COUNT(*) FROM %s%s t INNER JOIN %soauth2_clients c ON c.id = t.client_id WHERE LOWER(c.name) = ?';
            $accessTokens  = (int) $this->connection->fetchOne(
                sprintf($sql, $this->prefix, 'oauth2_accesstokens', $this->prefix),
                [self::WOOCOMMERCE_OAUTH_CLIENT]
            );
            $refreshTokens = (int) $this->connection->fetchOne(
                sprintf($sql, $this->prefix, 'oauth2_refreshtokens', $this->prefix),
                [self::WOOCOMMERCE_OAUTH_CLIENT]
            );

            return ($accessTokens + $refreshTokens) > 0;
        } catch (\Throwable $e) {
            $this->logger->warning('EwebSaasBundle: woocommerce state unreadable: {msg}', ['msg' => $e->getMessage()]);

            return null;
        }
    }
}
