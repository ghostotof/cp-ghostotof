<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * L'adresse d'un appelant réduite à son réseau, pour le journal d'appels MCP
 * (spec 0007, « Journal des appels ») : IPv4 en /24, IPv6 en /48. Assez pour
 * repérer un abus venant d'un réseau, plus assez pour désigner un poste. Un /48
 * reste souvent le réseau d'un abonné (un foyer, une entreprise) : c'est une
 * donnée personnelle réduite, pas une donnée anonyme (registre RGPD, §6 bis).
 *
 * Deux formes d'IPv6 portent une IPv4, qu'un /48 garderait :
 *
 *  - **l'IPv4 écrite en IPv6** (`::ffff:203.0.113.42`, une socket double pile) :
 *    elle est rendue en IPv4 (`203.0.113.0`), pour qu'un même /24 n'apparaisse
 *    pas sous deux valeurs ;
 *  - **6to4** (`2002::/16`, RFC 3056) : les octets 2 à 5 sont l'IPv4 du client,
 *    entière dans un /48 ; seuls les trois premiers sont gardés, comme un /24.
 *
 * La troncature elle-même est celle de Symfony, `IpUtils::anonymize()` (#673),
 * qui ne traite ni l'un ni l'autre de ces deux cas : elle garde l'IPv4 écrite
 * en IPv6 sous sa forme IPv6 (`::ffff:203.0.113.0`), et lit 6to4 comme une
 * IPv6 quelconque. Ils sont donc reconnus ici, sur les octets (`inet_pton`),
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

    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    private const string SIX_TO_FOUR_PREFIX = "\x20\x02";

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

        if (str_starts_with($bytes, self::IPV4_MAPPED_PREFIX)) {
            $bytes = substr($bytes, \strlen(self::IPV4_MAPPED_PREFIX));
            $address = (string) inet_ntop($bytes);
        }

        return new self(match (true) {
            4 === \strlen($bytes) => IpUtils::anonymize($address, self::IPV4_ERASED_BYTES),
            str_starts_with($bytes, self::SIX_TO_FOUR_PREFIX) => IpUtils::anonymize($address, v6Bytes: self::SIX_TO_FOUR_ERASED_BYTES),
            default => IpUtils::anonymize($address, self::IPV4_ERASED_BYTES, self::IPV6_ERASED_BYTES),
        });
    }
}
