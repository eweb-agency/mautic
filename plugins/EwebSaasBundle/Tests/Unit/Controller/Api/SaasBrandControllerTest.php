<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Controller\Api;

use MauticPlugin\EwebSaasBundle\Controller\Api\SaasBrandController;
use MauticPlugin\EwebSaasBundle\Service\BrandLogo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class SaasBrandControllerTest extends TestCase
{
    private BrandLogo&MockObject $logo;

    private SaasBrandController $controller;

    protected function setUp(): void
    {
        $this->logo       = $this->createMock(BrandLogo::class);
        $this->controller = new SaasBrandController($this->logo, new NullLogger());
    }

    public function testUnLogoEstDecodeEtEnregistre(): void
    {
        $this->logo->expects(self::once())->method('save')->with('octets')->willReturn(true);

        $response = $this->controller->logoAction($this->request(['image' => base64_encode('octets')]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"changed":true}', $response->getContent());
    }

    public function testUneImageNulleRetireLeLogo(): void
    {
        $this->logo->expects(self::once())->method('remove')->willReturn(false);
        $this->logo->expects(self::never())->method('save');

        $response = $this->controller->logoAction($this->request(['image' => null]));

        self::assertSame('{"changed":false}', $response->getContent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requetesInvalides(): iterable
    {
        yield 'pas du json' => ['image'];
        yield 'sans la clé' => ['{"logo":"x"}'];
        yield 'pas une chaîne' => ['{"image":12}'];
        yield 'base64 cassé' => ['{"image":"@@@"}'];
    }

    #[DataProvider('requetesInvalides')]
    public function testUneRequeteInvalideNeTouchePasAuLogo(string $body): void
    {
        $this->logo->expects(self::never())->method('save');
        $this->logo->expects(self::never())->method('remove');

        $response = $this->controller->logoAction(new Request([], [], [], [], [], [], $body));

        self::assertSame(400, $response->getStatusCode());
    }

    public function testUneImageRefuseeRepond422(): void
    {
        $this->logo->method('save')->willThrowException(new \InvalidArgumentException('Image de logo refusée.'));

        $response = $this->controller->logoAction($this->request(['image' => base64_encode('pas une image')]));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('{"error":"invalid_image"}', $response->getContent());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): Request
    {
        return new Request([], [], [], [], [], [], (string) json_encode($body));
    }
}
