<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Infrastructure\Log\TruncatedIp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'adresse du journal d'appels MCP (spec 0007, « Journal des appels ») :
 * IPv4 en /24, IPv6 en /48 — assez pour repérer un abus par réseau, plus assez
 * pour désigner un poste.
 */
final class TruncatedIpTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4 : le dernier octet tombe' => ['203.0.113.42', '203.0.113.0'];
        yield 'IPv4 déjà en /24' => ['198.51.100.0', '198.51.100.0'];
        yield 'IPv6 : 48 bits gardés' => ['2001:db8:85a3:8d3:1319:8a2e:370:7348', '2001:db8:85a3::'];
        yield 'IPv6 compressée' => ['2001:db8::1', '2001:db8::'];
        yield 'IPv6 boucle locale' => ['::1', '::'];
        // Le même /24 sous une seule valeur, quelle que soit la famille de la socket.
        yield 'IPv4 dans IPv6 : écrite en IPv4' => ['::ffff:203.0.113.42', '203.0.113.0'];
        yield 'IPv4 dans IPv6, en hexadécimal' => ['::ffff:cb00:712a', '203.0.113.0'];
        // 6to4 (RFC 3056) : les octets 2 à 5 sont l'IPv4 du client ; un /48 la
        // garderait entière. Elle est tronquée en /24 comme toute IPv4.
        yield '6to4 : l\'IPv4 portée tombe en /24' => ['2002:cb00:712a:1::5', '2002:cb00:7100::'];
    }

    #[DataProvider('addresses')]
    public function testTheAddressIsTruncatedToItsNetwork(string $address, string $truncated): void
    {
        self::assertSame($truncated, TruncatedIp::tryFrom($address)?->value);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unreadable(): iterable
    {
        yield 'absente (commande, sous-requête)' => [null];
        yield 'vide' => [''];
        yield 'pas une adresse' => ['localhost'];
        yield 'IPv4 hors bornes' => ['203.0.113.256'];
        yield 'avec un port' => ['203.0.113.42:8080'];
    }

    #[DataProvider('unreadable')]
    public function testAnUnreadableAddressGivesNothing(?string $address): void
    {
        self::assertNull(TruncatedIp::tryFrom($address));
    }
}
