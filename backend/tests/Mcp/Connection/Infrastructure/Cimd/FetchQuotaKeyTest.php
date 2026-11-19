<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Infrastructure\Cimd\FetchQuotaKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La clé du quota de récupération CIMD (#651, D-F) : ce que deux adresses
 * partagent (le même compteur) ou non, jugé sur la clé seule, sans limiteur ni
 * conteneur. Le comportement de bout en bout est dans
 * SymfonyClientMetadataFetchQuotaTest.
 */
final class FetchQuotaKeyTest extends TestCase
{
    /**
     * Des adresses qui doivent donner la même clé : une IPv6 est comptée par
     * son /64, quelle que soit son écriture, et une IPv4 écrite en IPv6 est
     * comptée comme cette IPv4.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function addressesSharingAKey(): iterable
    {
        yield 'deux IPv6 du même /64' => ['2001:db8:1:2::1', '2001:db8:1:2:ffff::9'];
        yield 'compressée et développée' => ['2001:db8:1:2::1', '2001:0db8:0001:0002:0000:0000:0000:0001'];
        yield 'majuscules et minuscules' => ['2001:DB8:1:2::1', '2001:db8:1:2::1'];
        yield 'IPv4 mappée, forme décimale' => ['198.51.100.7', '::ffff:198.51.100.7'];
        yield 'IPv4 mappée, forme hexadécimale' => ['198.51.100.7', '::ffff:c633:6407'];
        yield 'IPv4 mappée développée' => ['198.51.100.7', '0:0:0:0:0:ffff:c633:6407'];
        // NAT64 (RFC 6052) : tous les clients IPv4 derrière un traducteur
        // partageaient la clé `64:ff9b::/64` ; chacun a la sienne, celle de son IPv4.
        yield 'NAT64, forme décimale' => ['198.51.100.7', '64:ff9b::198.51.100.7'];
        yield 'NAT64, forme hexadécimale' => ['198.51.100.7', '64:ff9b::c633:6407'];
        // 6to4 (RFC 3056) : l'IPv4 est dans l'adresse, le reste est le choix de
        // son titulaire (65 536 /64 par IPv4, autant de compteurs sinon).
        yield '6to4' => ['198.51.100.7', '2002:c633:6407::1'];
        yield '6to4, autre /64 et autre hôte' => ['2002:c633:6407::1', '2002:c633:6407:ffff::2'];
        yield 'IPv4 compatible' => ['198.51.100.7', '::198.51.100.7'];
    }

    #[DataProvider('addressesSharingAKey')]
    public function testTheseAddressesShareAKey(string $first, string $second): void
    {
        self::assertSame(FetchQuotaKey::forAddress($first), FetchQuotaKey::forAddress($second));
    }

    /**
     * Des adresses qui ne doivent pas partager de compteur.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function addressesWithTheirOwnKey(): iterable
    {
        yield 'deux IPv4 voisines' => ['198.51.100.7', '198.51.100.8'];
        yield 'deux IPv4 de deux réseaux' => ['198.51.100.7', '203.0.113.7'];
        yield 'deux /64 voisins (quatrième groupe)' => ['2001:db8:1:2::1', '2001:db8:1:3::1'];
        yield 'deux /64 voisins (premier groupe)' => ['2001:db8:1:2::1', '2001:db9:1:2::1'];
        yield 'de part et d\'autre de la frontière' => ['2001:db8:1:1:ffff:ffff:ffff:ffff', '2001:db8:1:2::'];
        yield 'IPv4 et IPv6 sans rapport' => ['198.51.100.7', '2001:db8:1:2::1'];
        yield 'IPv4 mappée et une autre IPv4' => ['::ffff:198.51.100.7', '198.51.100.8'];
        yield 'deux IPv4 compatibles distinctes' => ['::198.51.100.7', '::198.51.100.8'];
        yield 'deux IPv4 NAT64 distinctes' => ['64:ff9b::198.51.100.7', '64:ff9b::198.51.100.8'];
        yield 'deux IPv4 6to4 distinctes' => ['2002:c633:6407::1', '2002:c633:6408::1'];
        yield 'une IPv6 globale et un 6to4' => ['2001:c633:6407::1', '2002:c633:6407::1'];
    }

    /**
     * `::` et `::1` suivent l'extracteur partagé (EmbeddedIpv4, `::/96` en
     * entier, comme le journal d'appels) : ce sont l'IPv4 « compatible »
     * `0.0.0.0` et `0.0.0.1`, non la clé commune d'un /64 `::/64`. Ni l'une
     * ni l'autre ne peut être l'adresse d'un client routable ; la boucle locale
     * ne compte pas pour `unknown`, et ne partage rien avec une IPv6 voisine.
     */
    public function testTheUnspecifiedAndLoopbackAddressesFollowTheSharedExtractor(): void
    {
        self::assertSame('0.0.0.0', FetchQuotaKey::forAddress('::'));
        self::assertSame('0.0.0.1', FetchQuotaKey::forAddress('::1'));
        self::assertNotSame(FetchQuotaKey::forAddress('::1'), FetchQuotaKey::forAddress('::2'));
    }

    #[DataProvider('addressesWithTheirOwnKey')]
    public function testTheseAddressesDoNotShareAKey(string $first, string $second): void
    {
        self::assertNotSame(FetchQuotaKey::forAddress($first), FetchQuotaKey::forAddress($second));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function addressesWithoutAKey(): iterable
    {
        yield 'absente' => [null];
        yield 'vide' => [''];
        yield 'texte' => ['pas une adresse'];
        yield 'IPv4 incomplète' => ['198.51.100'];
        yield 'IPv4 hors bornes' => ['198.51.100.256'];
        yield 'IPv6 tronquée' => ['2001:db8:1:2'];
        yield 'IPv6 avec une zone' => ['fe80::1%eth0'];
        yield 'IPv6 entre crochets' => ['[2001:db8::1]'];
        yield 'IPv6 avec un préfixe' => ['2001:db8::/64'];
    }

    #[DataProvider('addressesWithoutAKey')]
    public function testAnAbsentOrUnreadableAddressKeepsTheUnknownKey(?string $address): void
    {
        self::assertSame('unknown', FetchQuotaKey::forAddress($address));
    }

    public function testAKeyNeverCollidesWithTheUnknownOne(): void
    {
        foreach (['198.51.100.7', '2001:db8:1:2::1', '::ffff:198.51.100.7', '::'] as $address) {
            self::assertNotSame('unknown', FetchQuotaKey::forAddress($address));
        }
    }
}
