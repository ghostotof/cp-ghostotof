<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Network;

/**
 * Les quatre façons dont une adresse IPv6 porte une IPv4 (voir {@see EmbeddedIpv4}).
 */
enum EmbeddedIpv4Form
{
    /** `::ffff:a.b.c.d` (RFC 4291) : une socket double pile voit un client IPv4 ainsi. */
    case Mapped;

    /** `::a.b.c.d` (RFC 4291, dépréciée) : les douze premiers octets sont nuls. */
    case Compatible;

    /** `2002:aabb:ccdd::/48` (RFC 3056) : l'IPv4 est dans les octets 2 à 5. */
    case SixToFour;

    /** `64:ff9b::a.b.c.d` (RFC 6052) : le préfixe de traduction NAT64 / SIIT. */
    case Translated;
}
