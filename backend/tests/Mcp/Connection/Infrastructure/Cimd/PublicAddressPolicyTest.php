<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Infrastructure\Cimd\PublicAddressPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Une adresse à usage spécial n'est jamais publique (#651, troisième couche
 * contre le SSRF) : bouclage, réseaux privés, lien local — dont l'adresse des
 * métadonnées d'un hébergeur —, CGNAT, plages de documentation et de mesure,
 * multicast, plage réservée et diffusion, en IPv4 comme en IPv6, y compris
 * l'IPv4 portée par une adresse IPv6 (mappée, NAT64, 6to4). Une chaîne qui
 * n'est pas une adresse IP dans sa forme canonique n'est pas publique non
 * plus : la politique refuse ce qu'elle ne sait pas lire.
 *
 * Aucun test ne résout de nom : la politique juge une adresse déjà obtenue.
 */
final class PublicAddressPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function publicAddresses(): iterable
    {
        yield 'IPv4 publique' => ['1.1.1.1'];
        yield 'IPv4 publique, autre plage' => ['160.79.104.10'];
        yield 'IPv6 publique' => ['2606:4700:4700::1111'];
    }

    #[DataProvider('publicAddresses')]
    public function testAGloballyRoutableAddressIsPublic(string $address): void
    {
        self::assertTrue(new PublicAddressPolicy()->isPublic($address));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonPublicAddresses(): iterable
    {
        yield 'bouclage IPv4' => ['127.0.0.1'];
        yield 'réseau privé 10/8' => ['10.0.0.1'];
        yield 'réseau privé 172.16/12' => ['172.16.0.1'];
        yield 'réseau privé 192.168/16' => ['192.168.1.1'];
        yield 'lien local, métadonnées de l\'hébergeur' => ['169.254.169.254'];
        yield 'CGNAT 100.64/10' => ['100.64.0.1'];
        yield 'adresse non spécifiée IPv4' => ['0.0.0.0'];
        yield 'plage de documentation' => ['192.0.2.1'];
        yield 'plage de mesure 198.18/15' => ['198.18.0.1'];
        yield 'multicast IPv4' => ['224.0.0.1'];
        yield 'multicast IPv4, SSDP' => ['239.255.255.250'];
        yield 'plage réservée 240/4' => ['240.0.0.1'];
        yield 'diffusion' => ['255.255.255.255'];
        yield 'bouclage IPv6' => ['::1'];
        yield 'adresse non spécifiée IPv6' => ['::'];
        yield 'lien local IPv6' => ['fe80::1'];
        yield 'adresse locale unique fc00::/7' => ['fc00::1'];
        yield 'adresse locale unique, moitié fd' => ['fd00::1'];
        yield 'multicast IPv6' => ['ff02::1'];
        yield 'IPv4 mappée, bouclage' => ['::ffff:127.0.0.1'];
        yield 'IPv4 mappée, réseau privé' => ['::ffff:10.0.0.1'];
        yield 'IPv4 mappée, adresse pourtant publique' => ['::ffff:1.1.1.1'];
        yield 'NAT64 vers le bouclage' => ['64:ff9b::7f00:1'];
        yield '6to4 vers le bouclage' => ['2002:7f00:1::'];
        yield 'plage de documentation IPv6' => ['2001:db8::1'];
        yield 'nom d\'hôte' => ['not-an-ip'];
        yield 'chaîne vide' => [''];
        yield 'adresse suivie d\'une espace' => ['1.1.1.1 '];
        yield 'notation hexadécimale abrégée' => ['0x7f.1'];
        yield 'notation octale sur un entier' => ['017700000001'];
    }

    #[DataProvider('nonPublicAddresses')]
    public function testASpecialPurposeAddressOrAnythingElseIsNotPublic(string $address): void
    {
        self::assertFalse(new PublicAddressPolicy()->isPublic($address));
    }

    /**
     * La politique ajoute le multicast à la liste de Symfony parce que
     * celle-ci ne le couvre pas. Si une montée de Symfony l'y ajoute, ce test
     * le dit : l'ajout local de PublicAddressPolicy se retire alors.
     */
    public function testTheMulticastRangesAreAddedToSymfonysList(): void
    {
        self::assertNotContains('224.0.0.0/4', IpUtils::PRIVATE_SUBNETS);
        self::assertNotContains('ff00::/8', IpUtils::PRIVATE_SUBNETS);
    }
}
