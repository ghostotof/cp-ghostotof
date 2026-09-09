<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Manifest;

use App\Portfolio\Watch\Infrastructure\ReadsUntrustedArrays;

/**
 * Relit le fichier de versions produit pendant la construction de l'image.
 *
 * Même symétrie que pour le manifeste de paquets : un constructeur qui écrit au
 * build, un lecteur qui relit à l'exécution. Le fichier voyage dans l'image, il
 * décrit donc exactement ce que celle-ci déploie et ne peut pas s'en écarter.
 *
 * Son absence n'est pas une erreur. En développement, l'image n'est pas
 * construite : le fichier n'existe pas tant que `app:watch:build-versions` n'a
 * pas été lancé, et les produits concernés s'affichent alors sans version — ce
 * que la page sait déjà présenter. Faire échouer le rafraîchissement pour cela
 * serait hors de proportion.
 */
final readonly class FileDeployedVersionsReader
{
    use ReadsUntrustedArrays;

    public function __construct(private string $versionsPath)
    {
    }

    /**
     * @return array<string, string> version par slug ; tableau vide si le
     *                               fichier est absent ou illisible
     */
    public function read(): array
    {
        if (!is_readable($this->versionsPath)) {
            return [];
        }

        $content = file_get_contents($this->versionsPath);

        if (false === $content) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($content, true);

        if (!\is_array($decoded)) {
            return [];
        }

        $versions = $decoded['versions'] ?? null;

        if (!\is_array($versions)) {
            return [];
        }

        $collected = [];

        foreach ($versions as $slug => $version) {
            if (\is_string($slug) && '' !== $slug && \is_string($version) && '' !== $version) {
                $collected[$slug] = $version;
            }
        }

        return $collected;
    }
}
