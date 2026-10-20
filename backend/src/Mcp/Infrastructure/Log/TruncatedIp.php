<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Log;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * L'adresse d'un appelant réduite à son réseau, pour le journal d'appels MCP
 * (spec 0007, « Journal des appels ») : IPv4 en /24, IPv6 en /48. Assez pour
 * repérer un abus venant d'un réseau, plus assez pour désigner un poste — le
 * journal ne garde pas de donnée personnelle complète (registre RGPD).
 *
 * Une adresse IPv4 écrite en IPv6 (`::ffff:203.0.113.42`) est tronquée comme
 * l'IPv4 qu'elle porte : `IpUtils::anonymize()` la reconnaît, une troncature
 * IPv6 en /48 l'aurait gardée entière.
 */
final readonly class TruncatedIp
{
    /** Octets mis à zéro : le dernier d'une IPv4 (/24), les dix derniers d'une IPv6 (/48). */
    private const int IPV4_BYTES = 1;

    private const int IPV6_BYTES = 10;

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

        return new self(IpUtils::anonymize($address, self::IPV4_BYTES, self::IPV6_BYTES));
    }
}
