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
 * multicast, plage réservée et diffusion, affectations de protocoles, relais
 * 6to4, préfixe de rejet, ORCHID, SRv6, site local déprécié, en IPv4 comme en
 * IPv6, y compris l'IPv4 portée par une adresse IPv6 (mappée, NAT64, 6to4).
 * Une chaîne qui n'est pas une adresse IP dans sa forme canonique n'est pas
 * publique non plus : la politique refuse ce qu'elle ne sait pas lire.
 *
 * Aucun test ne résout de nom : la politique juge une adresse déjà obtenue.
 */
final class PublicAddressPolicyTest extends TestCase
{
    /**
     * Les plages que PublicAddressPolicy ajoute à la liste de Symfony, chacune
     * avec une adresse qu'elle contient.
     */
    private const array LOCALLY_ADDED_SUBNETS = [
        '224.0.0.0/4' => '224.0.0.1',
        'ff00::/8' => 'ff02::1',
        '192.0.0.0/24' => '192.0.0.170',
        '192.88.99.0/24' => '192.88.99.1',
        '100::/64' => '100::1',
        '2001:10::/28' => '2001:10::1',
        '2001:20::/28' => '2001:20::1',
        '3fff::/20' => '3fff::1',
        '5f00::/16' => '5f00::1',
        'fec0::/10' => 'fec0::1',
    ];

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
        yield 'affectations de protocoles IETF 192.0.0/24' => ['192.0.0.170'];
        yield 'relais 6to4 192.88.99/24' => ['192.88.99.1'];
        yield 'préfixe de rejet 100::/64' => ['100::1'];
        yield 'ORCHID 2001:10::/28' => ['2001:10::1'];
        yield 'ORCHIDv2 2001:20::/28' => ['2001:20::1'];
        yield 'plage de documentation IPv6 3fff::/20' => ['3fff::1'];
        yield 'identifiants de segment SRv6 5f00::/16' => ['5f00::1'];
        yield 'site local déprécié fec0::/10' => ['fec0::1'];
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
     * La politique ajoute le multicast et huit autres plages à usage spécial
     * à la liste de Symfony parce que celle-ci ne les couvre pas. Si une
     * montée de Symfony en couvre une, ce test le dit : l'entrée locale de
     * PublicAddressPolicy se retire alors.
     *
     * Deux lectures par plage : le préfixe n'est pas écrit dans la liste, et
     * aucune plage de la liste ne contient son adresse — Symfony écrit
     * certains préfixes autrement (`2001:0002::/48`), ou pourrait en ajouter
     * un plus large.
     */
    public function testTheMulticastRangesAreAddedToSymfonysList(): void
    {
        foreach (self::LOCALLY_ADDED_SUBNETS as $subnet => $address) {
            self::assertNotContains($subnet, IpUtils::PRIVATE_SUBNETS);
            self::assertFalse(IpUtils::checkIp($address, IpUtils::PRIVATE_SUBNETS), $subnet);
        }
    }
}
