<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use MauticPlugin\EwebSaasBundle\Service\IntegrationStateReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Contrat des états d'intégration :
 *  - « autorisé » ne veut pas dire « activé » : un connecteur avec des clés
 *    mais dépublié reste débranché pour le portail, et un tableau vide
 *    sérialisé ne compte pas comme des clés ;
 *  - WooCommerce est connectée dès qu'un jeton (d'accès OU de rafraîchissement)
 *    a été délivré au client OAuth de l'extension ;
 *  - une source illisible rend un état INCONNU (null / vide), jamais un faux
 *    « débranché », et n'entraîne pas les autres sources.
 */
class IntegrationStateReaderTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    public function testReadsEverySourceIndependently(): void
    {
        $this->connection->method('fetchOne')->willReturnCallback(
            static function (string $sql): int {
                if (str_contains($sql, 'webhooks')) {
                    return 2;
                }
                if (str_contains($sql, 'oauth2_accesstokens')) {
                    return 0;
                }
                if (str_contains($sql, 'oauth2_refreshtokens')) {
                    return 1;
                }

                return 0;
            }
        );
        $this->connection->method('fetchAllAssociative')->willReturn([
            ['name' => 'Zapier', 'is_published' => 1, 'api_keys' => 'a:0:{}'],
            ['name' => 'Hubspot', 'is_published' => 1, 'api_keys' => 'encrypted-blob'],
            ['name' => 'Zoho', 'is_published' => 0, 'api_keys' => 'encrypted-blob'],
        ]);

        $states = $this->createReader()->getStates();

        $this->assertSame(2, $states['webhooks']['published']);
        $this->assertSame(['published' => true, 'authorized' => false], $states['plugins']['zapier']);
        $this->assertSame(['published' => true, 'authorized' => true], $states['plugins']['hubspot']);
        $this->assertSame(['published' => false, 'authorized' => true], $states['plugins']['zoho']);
        $this->assertArrayNotHasKey('salesforce', $states['plugins'], 'un connecteur jamais configuré est absent');
        $this->assertTrue($states['woocommerce']['connected'], 'un jeton de rafraîchissement suffit');
        $this->assertNotEmpty($states['generatedAt']);
    }

    public function testAnUnreadableSourceIsUnknownAndDoesNotBreakTheOthers(): void
    {
        $this->connection->method('fetchOne')->willReturnCallback(
            static function (string $sql): int {
                if (str_contains($sql, 'webhooks')) {
                    throw new \RuntimeException('table missing');
                }

                return 0;
            }
        );
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $states = $this->createReader()->getStates();

        $this->assertNull($states['webhooks']['published'], 'inconnu, pas un zéro inventé');
        $this->assertSame([], $states['plugins']);
        $this->assertFalse($states['woocommerce']['connected']);
    }

    public function testWooCommerceUnknownWhenTokensUnreadable(): void
    {
        $this->connection->method('fetchOne')->willReturnCallback(
            static function (string $sql): int {
                if (str_contains($sql, 'oauth2_')) {
                    throw new \RuntimeException('table missing');
                }

                return 0;
            }
        );
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $states = $this->createReader()->getStates();

        $this->assertSame(0, $states['webhooks']['published']);
        $this->assertNull($states['woocommerce']['connected']);
    }

    private function createReader(): IntegrationStateReader
    {
        return new IntegrationStateReader($this->connection, new NullLogger());
    }
}
