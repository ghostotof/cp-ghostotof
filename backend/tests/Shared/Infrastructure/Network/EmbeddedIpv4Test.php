<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Network;

use App\Shared\Infrastructure\Network\EmbeddedIpv4;
use App\Shared\Infrastructure\Network\EmbeddedIpv4Form;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'IPv4 qu'une IPv6 porte (#651, #673) : les quatre formes reconnues, la
 * limite de chacune, et ce qui n'en est pas une. Jugé sur les octets
 * (`inet_pton`), sans la libc d'écriture d'`inet_ntop` pour l'IPv6, dont la
 * forme textuelle varie (musl, glibc).
 */
final class EmbeddedIpv4Test extends TestCase
{
    /**
     * @return iterable<string, array{string, EmbeddedIpv4Form, string}>
     */
    public static function carriers(): iterable
    {
        yield 'mappée, forme décimale' => ['::ffff:198.51.100.7', EmbeddedIpv4Form::Mapped, '198.51.100.7'];
        yield 'mappée, forme hexadécimale' => ['::ffff:c633:6407', EmbeddedIpv4Form::Mapped, '198.51.100.7'];
        yield 'mappée développée' => ['0:0:0:0:0:ffff:c633:6407', EmbeddedIpv4Form::Mapped, '198.51.100.7'];
        yield 'NAT64' => ['64:ff9b::198.51.100.7', EmbeddedIpv4Form::Translated, '198.51.100.7'];
        yield 'NAT64, forme hexadécimale' => ['64:ff9b::c633:6407', EmbeddedIpv4Form::Translated, '198.51.100.7'];
        yield '6to4' => ['2002:c633:6407::1', EmbeddedIpv4Form::SixToFour, '198.51.100.7'];
        yield '6to4, autre sous-réseau et hôte' => ['2002:c633:6407:ffff::2', EmbeddedIpv4Form::SixToFour, '198.51.100.7'];
        yield 'compatible' => ['::198.51.100.7', EmbeddedIpv4Form::Compatible, '198.51.100.7'];
        // `::/96` en entier, comme TruncatedIp : deux IPv4 compatibles
        // distinctes ont deux adresses, et `::` comme `::1` en sont une.
        yield 'compatible : une autre IPv4' => ['::203.0.113.42', EmbeddedIpv4Form::Compatible, '203.0.113.42'];
        yield 'la non spécifiée `::`' => ['::', EmbeddedIpv4Form::Compatible, '0.0.0.0'];
        yield 'la boucle locale `::1`' => ['::1', EmbeddedIpv4Form::Compatible, '0.0.0.1'];
    }

    #[DataProvider('carriers')]
    public function testItReadsTheIpv4AnAddressCarries(string $ipv6, EmbeddedIpv4Form $form, string $ipv4): void
    {
        $embedded = EmbeddedIpv4::tryFromPacked((string) inet_pton($ipv6));

        self::assertNotNull($embedded);
        self::assertSame($form, $embedded->form);
        self::assertSame($ipv4, $embedded->address);
    }

    /**
     * Les frontières des préfixes : un octet de plus ou de moins n'en est plus un.
     *
     * @return iterable<string, array{string}>
     */
    public static function ordinaryAddresses(): iterable
    {
        yield 'IPv6 globale' => ['2001:db8:1:2::1'];
        yield 'lien local' => ['fe80::1'];
        yield '6to4 : un octet avant (2001)' => ['2001:c633:6407::1'];
        yield '6to4 : un octet après (2003)' => ['2003:c633:6407::1'];
        yield 'NAT64 : un groupe de plus (64:ff9b:1::/96)' => ['64:ff9b:1::198.51.100.7'];
        yield 'NAT64 : autre préfixe (64:ff9a::/96)' => ['64:ff9a::198.51.100.7'];
        yield 'mappée : un octet avant (::fffe:)' => ['::fffe:c633:6407'];
        yield 'mappée : un groupe de trop (0:0:0:0:1:ffff:)' => ['0:0:0:0:1:ffff:c633:6407'];
        yield 'compatible : un bit dans les douze premiers octets' => ['0:0:0:0:0:1:c633:6407'];
        yield 'compatible : un bit au troisième groupe' => ['0:0:1::198.51.100.7'];
    }

    #[DataProvider('ordinaryAddresses')]
    public function testAnOrdinaryAddressCarriesNoIpv4(string $ipv6): void
    {
        self::assertNull(EmbeddedIpv4::tryFromPacked((string) inet_pton($ipv6)));
    }

    public function testAnIpv4ItselfIsNotAnIpv4CarriedByAnIpv6(): void
    {
        self::assertNull(EmbeddedIpv4::tryFromPacked((string) inet_pton('198.51.100.7')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnAddress(): iterable
    {
        yield 'vide' => [''];
        yield 'quinze octets' => [str_repeat("\0", 15)];
        yield 'dix-sept octets' => [str_repeat("\0", 17)];
    }

    #[DataProvider('notAnAddress')]
    public function testBytesOfTheWrongLengthCarryNothing(string $packed): void
    {
        self::assertNull(EmbeddedIpv4::tryFromPacked($packed));
    }
}
