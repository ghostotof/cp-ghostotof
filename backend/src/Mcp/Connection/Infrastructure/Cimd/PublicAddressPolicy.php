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
 *   les adresses locales uniques (`fc00::/7`) ;
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
 * - le multicast (`224.0.0.0/4`, `ff00::/8`).
 *
 * Tout cela est la liste de Symfony, IpUtils::PRIVATE_SUBNETS, sauf le
 * multicast, qu'elle ne couvre pas et que cette classe ajoute. Le test le
 * dit le jour où Symfony l'y ajoute : l'ajout local se retire alors.
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

    public function isPublic(string $address): bool
    {
        // D'abord la forme : IpUtils::checkIp() rend « hors de la plage »
        // pour une chaîne qu'il ne sait pas lire, ce qui la dirait publique.
        if (false === filter_var($address, \FILTER_VALIDATE_IP)) {
            return false;
        }

        return !IpUtils::checkIp($address, [...IpUtils::PRIVATE_SUBNETS, ...self::MULTICAST_SUBNETS]);
    }
}
