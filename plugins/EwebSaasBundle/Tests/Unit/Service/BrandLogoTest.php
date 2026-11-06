<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Tests\Unit\Service;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\EwebSaasBundle\Service\BrandLogo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class BrandLogoTest extends TestCase
{
    /** Un PNG de 1 pixel. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** Le même pixel, sur un autre fond. */
    private const OTHER_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private string $root;

    private BrandLogo $logo;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/logo-'.uniqid();
        mkdir($this->root.'/media/images', 0777, true);

        $paths = $this->createMock(PathsHelper::class);
        $paths->method('getSystemPath')->willReturnCallback(fn (string $name, bool $absolute = false): string => $absolute ? $this->root.'/media/images' : 'media/images');

        $this->logo = new BrandLogo($paths);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testSansLogoRienNEstAffiche(): void
    {
        self::assertNull($this->logo->assetPath());
        self::assertFalse($this->logo->remove());
    }

    public function testUnLogoEstEcritDansLesImagesDeLEspace(): void
    {
        self::assertTrue($this->logo->save((string) base64_decode(self::PNG)));

        $path = (string) $this->logo->assetPath();
        self::assertMatchesRegularExpression('#^media/images/sendly/logo-[0-9a-f]{16}\.png$#', $path);
        self::assertFileExists($this->root.'/'.$path);
    }

    public function testRenvoyerLeMemeLogoNEcritRien(): void
    {
        $this->logo->save((string) base64_decode(self::PNG));

        self::assertFalse($this->logo->save((string) base64_decode(self::PNG)));
    }

    public function testUnNouveauLogoRemplaceLAncien(): void
    {
        $this->logo->save((string) base64_decode(self::PNG));
        $before = (string) $this->logo->assetPath();

        self::assertTrue($this->logo->save((string) base64_decode(self::OTHER_PNG)));

        self::assertNotSame($before, $this->logo->assetPath());
        self::assertFileDoesNotExist($this->root.'/'.$before);
        self::assertCount(1, (array) glob($this->root.'/media/images/sendly/*'));
    }

    public function testRetirerLeLogo(): void
    {
        $this->logo->save((string) base64_decode(self::PNG));

        self::assertTrue($this->logo->remove());
        self::assertNull($this->logo->assetPath());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refus(): iterable
    {
        yield 'vide' => [''];
        yield 'texte' => ['pas une image'];
        yield 'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield 'php' => ['<?php echo 1;'];
        yield 'gif' => [(string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')];
        yield 'trop lourd' => [str_repeat('a', BrandLogo::MAX_BYTES + 1)];
    }

    #[DataProvider('refus')]
    public function testCeQuiNEstPasUneImageAccepteeEstRefuse(string $bytes): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->logo->save($bytes);
    }

    public function testUnRefusLaisseLeLogoEnPlace(): void
    {
        $this->logo->save((string) base64_decode(self::PNG));
        $before = $this->logo->assetPath();

        try {
            $this->logo->save('pas une image');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame($before, $this->logo->assetPath());
    }
}
