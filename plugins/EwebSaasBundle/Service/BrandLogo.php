<?php

declare(strict_types=1);

namespace MauticPlugin\EwebSaasBundle\Service;

use Mautic\CoreBundle\Helper\PathsHelper;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le logo de l'organisation, envoyé par le portail : les espaces n'en ont pas
 * d'autre. Il vit dans `media/images`, volume commun aux conteneurs de l'espace
 * et servi tel quel par le serveur web.
 *
 * Le nom du fichier porte l'empreinte de son contenu : le navigateur ne garde
 * jamais un ancien logo, et renvoyer le même logo n'écrit rien.
 */
class BrandLogo
{
    public const MAX_BYTES = 2_097_152;

    private const MAX_SIDE = 4096;

    private const DIRECTORY = 'sendly';

    /**
     * Aucun SVG : le fichier est servi depuis le domaine de l'espace, et un
     * SVG peut y exécuter du script.
     */
    private const EXTENSIONS = [
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_WEBP => 'webp',
    ];

    public function __construct(
        private readonly PathsHelper $paths,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Le chemin à donner à `getAssetUrl`, s'il y a un logo.
     */
    public function assetPath(): ?string
    {
        $file = $this->current();

        return null === $file ? null : $this->paths->getSystemPath('images', false).'/'.self::DIRECTORY.'/'.basename($file);
    }

    /**
     * @return bool vrai si le logo a changé
     *
     * @throws \InvalidArgumentException si ce n'est pas une image acceptée
     */
    public function save(string $bytes): bool
    {
        $extension = $this->extension($bytes);
        $name      = 'logo-'.substr(hash('sha256', $bytes), 0, 16).'.'.$extension;
        $current   = $this->current();

        if (null !== $current && basename($current) === $name) {
            return false;
        }

        $this->filesystem->dumpFile($this->directory().'/'.$name, $bytes);
        $this->filesystem->chmod($this->directory().'/'.$name, 0644);

        if (null !== $current) {
            $this->filesystem->remove($current);
        }

        return true;
    }

    /**
     * @return bool vrai s'il y avait un logo à retirer
     */
    public function remove(): bool
    {
        $current = $this->current();

        if (null === $current) {
            return false;
        }

        $this->filesystem->remove($current);

        return true;
    }

    private function extension(string $bytes): string
    {
        if ('' === $bytes || strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Taille de logo refusée.');
        }

        $size = @getimagesizefromstring($bytes);

        if (false === $size || !isset(self::EXTENSIONS[$size[2]]) || $size[0] < 1 || $size[1] < 1 || max($size[0], $size[1]) > self::MAX_SIDE) {
            throw new \InvalidArgumentException('Image de logo refusée.');
        }

        return self::EXTENSIONS[$size[2]];
    }

    private function current(): ?string
    {
        $files = glob($this->directory().'/logo-*.*');

        return false === $files || [] === $files ? null : $files[0];
    }

    private function directory(): string
    {
        return $this->paths->getSystemPath('images', true).'/'.self::DIRECTORY;
    }
}
