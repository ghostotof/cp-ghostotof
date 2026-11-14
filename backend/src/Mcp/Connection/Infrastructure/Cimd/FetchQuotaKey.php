<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

/**
 * La clé du quota de récupération des documents CIMD (#651, D-F) : ce que
 * deux adresses source partagent comme compteur.
 *
 *  - **une IPv6 est comptée par son /64** : un abonné ou un hébergeur reçoit
 *    au moins un /64, dont il change l'adresse à volonté ; compter l'adresse
 *    entière lui donnerait un compteur neuf à chaque essai, donc des
 *    récupérations sortantes sans borne. La clé est le préfixe normalisé
 *    suivi de `/64`, une même écriture quelle que soit celle de l'adresse
 *    (compressée, développée, majuscules) ;
 *  - **une IPv4 reste entière** : un /24 mêlerait des voisins sans rapport,
 *    alors qu'une IPv4 n'est pas, à elle seule, un réseau qu'on tient ;
 *  - **une IPv4 écrite en IPv6** (`::ffff:a.b.c.d`, une socket double pile)
 *    est comptée comme cette IPv4 entière : c'est la même source que celle
 *    d'une socket IPv4, elle partage donc son compteur ;
 *  - **une adresse absente ou illisible** garde la clé `unknown`, la voie sûre
 *    (tous ces appels partagent un seul compteur : il limite trop, jamais pas
 *    assez).
 *
 * Pour ce limiteur seulement : les autres limiteurs du site comptent encore
 * une IPv6 par adresse entière (limite acceptée, plan T12). Une classe dédiée
 * plutôt qu'une méthode privée du quota, pour la juger sur la clé seule, sans
 * limiteur ni conteneur (FetchQuotaKeyTest) ; elle n'étend pas `TruncatedIp`,
 * objet de journal (/24, /48) dont les règles sont d'un autre propos.
 */
final readonly class FetchQuotaKey
{
    public const string UNKNOWN = 'unknown';

    /** Octets gardés d'une IPv6 : son /64. */
    private const int IPV6_KEPT_BYTES = 8;

    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    public static function forAddress(?string $address): string
    {
        // Refuse aussi une zone (`fe80::1%eth0`), des crochets et un préfixe (`/64`).
        if (null === $address || false === filter_var($address, \FILTER_VALIDATE_IP)) {
            return self::UNKNOWN;
        }

        $bytes = (string) inet_pton($address);

        if (str_starts_with($bytes, self::IPV4_MAPPED_PREFIX)) {
            $bytes = substr($bytes, \strlen(self::IPV4_MAPPED_PREFIX));
        }

        if (4 === \strlen($bytes)) {
            return (string) inet_ntop($bytes);
        }

        // Les octets du /64, le reste à zéro : `inet_ntop()` en donne l'écriture canonique.
        $network = substr($bytes, 0, self::IPV6_KEPT_BYTES).str_repeat("\0", 16 - self::IPV6_KEPT_BYTES);

        return \sprintf('%s/64', inet_ntop($network));
    }
}
