<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Domain\Entity;

use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Exception\InvalidWatchedProductException;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use PHPUnit\Framework\TestCase;

final class WatchedProductTest extends TestCase
{
    private function postgres(): WatchedProduct
    {
        return new WatchedProduct('postgresql', 'PostgreSQL', VersionSource::MANUAL, '18.4', 2);
    }

    public function testConstructorSetsAllProperties(): void
    {
        $product = $this->postgres();

        self::assertNull($product->getId());
        self::assertSame('postgresql', $product->getSlug());
        self::assertSame('PostgreSQL', $product->getLabel());
        self::assertSame(VersionSource::MANUAL, $product->getVersionSource());
        self::assertSame('18.4', $product->getVersion());
        self::assertSame(2, $product->getPosition());
    }

    /**
     * Décision D2 : la version de PHP et celle de Symfony sont lues au runtime,
     * jamais saisies. Le champ `version` reste donc vide en base pour ces deux
     * entrées — c'est le résolveur qui la fournira (T1.3).
     */
    public function testARuntimeSourcedProductCarriesNoStoredVersion(): void
    {
        $php = new WatchedProduct('php', 'PHP', VersionSource::RUNTIME_PHP, null, 0);

        self::assertSame(VersionSource::RUNTIME_PHP, $php->getVersionSource());
        self::assertNull($php->getVersion());
    }

    /**
     * L'invariant de D2 rendu structurel : un produit dont la version est
     * saisie à la main *doit* en avoir une. Sans cette garde, une entrée
     * silencieusement dépourvue de version afficherait un radar de veille
     * incomplet — exactement ce que la page prétend éviter.
     */
    public function testAManuallyVersionedProductRequiresAVersion(): void
    {
        $this->expectException(InvalidWatchedProductException::class);

        new WatchedProduct('postgresql', 'PostgreSQL', VersionSource::MANUAL, null, 0);
    }

    public function testAManuallyVersionedProductRejectsABlankVersion(): void
    {
        $this->expectException(InvalidWatchedProductException::class);

        new WatchedProduct('postgresql', 'PostgreSQL', VersionSource::MANUAL, '   ', 0);
    }

    /**
     * Le pendant du test précédent : accepter une version saisie pour PHP
     * laisserait croire qu'elle fait autorité, alors que le runtime la
     * contredirait au premier rafraîchissement. Mieux vaut refuser l'ambiguïté.
     */
    public function testARuntimeSourcedProductRejectsAProvidedVersion(): void
    {
        $this->expectException(InvalidWatchedProductException::class);

        new WatchedProduct('php', 'PHP', VersionSource::RUNTIME_PHP, '8.5.9', 0);
    }

    public function testUpdateReplacesEveryMutablePropertyButNotTheSlug(): void
    {
        $product = $this->postgres();

        $product->update('PostgreSQL (base principale)', VersionSource::MANUAL, '18.5', 5);

        self::assertSame('PostgreSQL (base principale)', $product->getLabel());
        self::assertSame('18.5', $product->getVersion());
        self::assertSame(5, $product->getPosition());
        // Changer le slug revient à suivre un autre produit : c'est une création.
        self::assertSame('postgresql', $product->getSlug());
    }

    public function testUpdateEnforcesTheSameVersionInvariant(): void
    {
        $product = $this->postgres();

        $this->expectException(InvalidWatchedProductException::class);

        $product->update('PostgreSQL', VersionSource::MANUAL, null, 2);
    }
}
