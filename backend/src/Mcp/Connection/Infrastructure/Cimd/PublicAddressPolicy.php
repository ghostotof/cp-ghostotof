<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Dit si une adresse IP est publique, c'est-à-dire joignable sur Internet et
 * sans usage spécial (CIMD, #651 ; draft-ietf-oauth-client-id-metadata-document-03,
 * §10.7, qui renvoie à la RFC 6890). Troisième couche contre le SSRF (plan de
 * la tâche 12, D-D) : le document de métadonnées d'un client n'est récupéré
 * que si toutes les adresses de son hôte sont publiques, sans quoi une URL de
 * `client_id` ferait interroger au serveur son propre réseau.
 *
 * Ne sont jamais publiques :
 * - le bouclage (`127.0.0.0/8`, `::1`) et l'adresse non spécifiée
 *   (`0.0.0.0/8`, `::`) ;
 * - les réseaux privés (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) et
 *   les adresses locales uniques (`fc00::/7`), et l'ancien site local
 *   (`fec0::/10`, déprécié par la RFC 3879) ;
 * - le lien local (`169.254.0.0/16`, `fe80::/10`), où vit l'adresse des
 *   métadonnées d'un hébergeur (`169.254.169.254`) ;
 * - l'espace partagé des opérateurs, CGNAT (`100.64.0.0/10`) ;
 * - les plages de documentation et de mesure (`192.0.2.0/24`,
 *   `198.51.100.0/24`, `203.0.113.0/24`, `198.18.0.0/15`, `2001:db8::/32`,
 *   `2001:2::/48`) ;
 * - la plage réservée et la diffusion (`240.0.0.0/4`, qui contient
 *   `255.255.255.255`) ;
 * - une IPv4 portée par une adresse IPv6, quelle que soit cette IPv4 : mappée
 *   (`::ffff:0:0/96`), compatible (`::/96`), NAT64 (`64:ff9b::/96`,
 *   `64:ff9b:1::/48`), 6to4 (`2002::/16`), Teredo (`2001::/32`) — la juger
 *   demanderait de la décoder, la refuser ne coûte qu'un client mal hébergé ;
 * - le multicast (`224.0.0.0/4`, `ff00::/8`) ;
 * - les autres plages à usage spécial ou réservées : affectations de
 *   protocoles de l'IETF (`192.0.0.0/24`, `2001::/23`, qui contient ORCHID),
 *   relais 6to4 (`192.88.99.0/24`), préfixe de rejet (`100::/64`), préfixe
 *   factice (`100:0:0:1::/64`), documentation (`3fff::/20`), identifiants de
 *   segment SRv6 (`5f00::/16`).
 *
 * Un bloc d'affectations de protocoles est refusé en entier, y compris les
 * exceptions que le registre de l'IANA dit joignables : `192.0.0.9` et
 * `192.0.0.10` en IPv4, `2001:1::1` à `2001:1::3`, `2001:3::/32`,
 * `2001:4:112::/48`, `2001:20::/28` et `2001:30::/28` en IPv6. Ce sont des
 * adresses de service (anycast, relais, identifiants), jamais l'hôte d'un
 * document de métadonnées : les refuser ne coûte rien, les suivre une à une
 * ferait dépendre la politique d'un registre qui change.
 *
 * La liste de Symfony, IpUtils::PRIVATE_SUBNETS, couvre tout cela sauf les
 * deux derniers points, que cette classe ajoute (MULTICAST_SUBNETS,
 * UNLISTED_SPECIAL_PURPOSE_SUBNETS). Le test le dit le jour où Symfony
 * couvre l'une de ces plages : l'entrée locale se retire alors — ainsi de
 * `fec0::/10`, couvert par Symfony depuis 8.1.12.
 *
 * La liste complète, {@see self::SUBNETS}, est la source unique : la couche 3
 * (`isPublic()`) et la couche 5 (`NoPrivateNetworkHttpClient`, câblé dans
 * `services.yaml`) la lisent, pour que ce que l'une refuse, l'autre le refuse.
 *
 * Une chaîne qui n'est pas une adresse IP n'est pas publique : un nom d'hôte,
 * la chaîne vide, une adresse suivie d'un blanc, les notations que certains
 * résolveurs lisent comme une adresse (`0x7f.1`, `017700000001`). La
 * politique juge une adresse déjà résolue et refuse ce qu'elle ne sait pas
 * lire ; elle ne résout aucun nom.
 */
final readonly class PublicAddressPolicy
{
    /**
     * Les plages multicast, absentes de la liste de Symfony.
     */
    private const array MULTICAST_SUBNETS = ['224.0.0.0/4', 'ff00::/8'];

    /**
     * Les autres plages à usage spécial ou réservées, absentes elles aussi de
     * la liste de Symfony (registres d'adresses à usage spécial de l'IANA,
     * RFC 6890).
     */
    private const array UNLISTED_SPECIAL_PURPOSE_SUBNETS = [
        '192.0.0.0/24',   // affectations de protocoles de l'IETF (RFC 6890), en entier
        '192.88.99.0/24', // relais 6to4, déprécié (RFC 7526)
        '100::/64',       // préfixe de rejet (RFC 6666)
        '100:0:0:1::/64', // préfixe factice (RFC 9780)
        '2001::/23',      // affectations de protocoles de l'IETF (RFC 2928), en entier
        '3fff::/20',      // documentation (RFC 9637)
        '5f00::/16',      // identifiants de segment SRv6 (RFC 9602)
    ];

    /**
     * Tout ce qui n'est pas public, en notation CIDR : la liste de Symfony et
     * les deux ajouts ci-dessus. Publique pour être la liste de la couche 5.
     */
    public const array SUBNETS = [
        ...IpUtils::PRIVATE_SUBNETS,
        ...self::MULTICAST_SUBNETS,
        ...self::UNLISTED_SPECIAL_PURPOSE_SUBNETS,
    ];

    public function isPublic(string $address): bool
    {
        // D'abord la forme : IpUtils::checkIp() rend « hors de la plage »
        // pour une chaîne qu'il ne sait pas lire, ce qui la dirait publique.
        if (false === filter_var($address, \FILTER_VALIDATE_IP)) {
            return false;
        }

        return !IpUtils::checkIp($address, self::SUBNETS);
    }
}
