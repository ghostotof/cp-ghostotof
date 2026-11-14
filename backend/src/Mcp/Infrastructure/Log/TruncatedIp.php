<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

use App\Shared\Infrastructure\Network\EmbeddedIpv4;
use App\Shared\Infrastructure\Network\EmbeddedIpv4Form;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * L'adresse d'un appelant réduite à son réseau, pour le journal d'appels MCP
 * (spec 0007, « Journal des appels ») : IPv4 en /24, IPv6 en /48. Assez pour
 * repérer un abus venant d'un réseau, plus assez pour désigner un poste. Un /48
 * reste souvent le réseau d'un abonné (un foyer, une entreprise) : c'est une
 * donnée personnelle réduite, pas une donnée anonyme (registre RGPD, §6 bis).
 *
 * La reconnaissance des formes d'IPv6 qui portent une IPv4 est celle de
 * {@see EmbeddedIpv4} (`Shared`), partagée avec le quota des documents CIMD ;
 * seule la façon de tronquer chacune reste ici. Trois formes portent une IPv4, qu'un /48 garderait entière ou
 * effacerait :
 *
 *  - **l'IPv4 écrite en IPv6** (`::ffff:203.0.113.42`, une socket double pile) :
 *    elle est rendue en IPv4 (`203.0.113.0`), pour qu'un même /24 n'apparaisse
 *    pas sous deux valeurs ;
 *  - **6to4** (`2002::/16`, RFC 3056) : les octets 2 à 5 sont l'IPv4 du client,
 *    entière dans un /48 ; seuls les trois premiers sont gardés, comme un /24 ;
 *  - **le préfixe de traduction** (`64:ff9b::/96`, RFC 6052) : un serveur en
 *    IPv6 qui reçoit des clients IPv4 par un traducteur (NAT64, SIIT) les voit
 *    ainsi, l'IPv4 dans les 32 derniers bits, qu'un /48 effaçait — tous les
 *    clients sous `64:ff9b::`. Tronquée en /24, préfixe gardé (#673).
 *
 * La troncature elle-même est celle de Symfony, `IpUtils::anonymize()` (#673),
 * qui ne traite aucun de ces trois cas : elle garde l'IPv4 écrite en IPv6 sous
 * sa forme IPv6 (`::ffff:203.0.113.0`), et lit 6to4 et le préfixe de
 * traduction comme des IPv6 quelconques. Ils sont donc reconnus ici, sur les octets (`inet_pton`),
 * avant de lui passer l'adresse. Elle tronque en /24, comme une IPv4,
 * l'IPv4 « compatible » (`::203.0.113.42`, dépréciée), qu'un /48 réduisait à
 * `::`.
 */
final readonly class TruncatedIp
{
    /** Octets effacés : un d'une IPv4 (/24), dix d'une IPv6 (/48). */
    private const int IPV4_ERASED_BYTES = 1;

    private const int IPV6_ERASED_BYTES = 10;

    /** Tout sauf `2002:` et les trois premiers octets de l'IPv4 portée. */
    private const int SIX_TO_FOUR_ERASED_BYTES = 11;

    /** Le dernier octet de l'IPv4 portée, préfixe de 96 bits gardé. */
    private const int TRANSLATED_ERASED_BYTES = 1;

    private function __construct(
        public string $value,
    ) {
    }

    /**
     * `null` pour une adresse absente (une commande, une sous-requête) ou
     * illisible : le journal écrit alors `null` plutôt que de recopier ce qu'il
     * a reçu.
     */
    public static function tryFrom(?string $address): ?self
    {
        if (null === $address || false === filter_var($address, \FILTER_VALIDATE_IP)) {
            return null;
        }

        $bytes = (string) inet_pton($address);

        if (4 === \strlen($bytes)) {
            return new self(IpUtils::anonymize($address, v4Bytes: self::IPV4_ERASED_BYTES));
        }

        // La reconnaissance des quatre formes est celle de l'extracteur partagé
        // (EmbeddedIpv4) ; ici ne reste que la façon de tronquer chacune.
        $embedded = EmbeddedIpv4::tryFromPacked($bytes);

        return new self(match ($embedded?->form) {
            EmbeddedIpv4Form::Mapped => IpUtils::anonymize($embedded->address, v4Bytes: self::IPV4_ERASED_BYTES),
            EmbeddedIpv4Form::SixToFour => IpUtils::anonymize($address, v6Bytes: self::SIX_TO_FOUR_ERASED_BYTES),
            EmbeddedIpv4Form::Translated => IpUtils::anonymize($address, v6Bytes: self::TRANSLATED_ERASED_BYTES),
            // `v4Bytes` aussi : Symfony lit en IPv4 l'IPv4 « compatible »
            // (`::a.b.c.d`, douze octets nuls), tronquée en /24.
            EmbeddedIpv4Form::Compatible, null => IpUtils::anonymize($address, v4Bytes: self::IPV4_ERASED_BYTES, v6Bytes: self::IPV6_ERASED_BYTES),
        });
    }
}
