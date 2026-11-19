<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Network;

/**
 * L'IPv4 qu'une adresse IPv6 porte, ou rien (#651, #673) : un client IPv4 qui
 * atteint un serveur en IPv6 par une socket double pile, un traducteur NAT64 ou
 * un tunnel 6to4 y est vu sous une adresse IPv6 dont une partie est son IPv4.
 * Quatre formes, jugées sur les octets (`inet_pton`), jamais sur le texte :
 *
 *  - **mappée** `::ffff:a.b.c.d` (RFC 4291) : dix octets nuls, puis `ffff` ;
 *  - **traduite** `64:ff9b::/96` (RFC 6052) : l'IPv4 dans les 32 derniers bits ;
 *  - **6to4** `2002::/16` (RFC 3056) : l'IPv4 dans les octets 2 à 5, le reste
 *    (sous-réseau, hôte) étant le choix de son titulaire ;
 *  - **compatible** `::/96` (RFC 4291, dépréciée) : douze octets nuls. Le `/96`
 *    en entier, comme le fait `TruncatedIp` : `::` et `::1` en sont, lus comme
 *    `0.0.0.0` et `0.0.0.1`, jamais comme une adresse d'un client routable.
 *
 * Écrit une fois pour tous les compteurs et journaux qui doivent rapporter ces
 * adresses à l'IPv4 qu'elles portent : sans elle, un /64 ou un /48 efface
 * l'IPv4 (NAT64 : tous les clients sous un même préfixe) ou en laisse 65 536
 * variantes (6to4). Placée dans `Shared/Infrastructure/Network` parce que ses
 * deux premiers utilisateurs (le journal d'appels MCP, le quota des documents
 * CIMD) sont de deux contextes ; les autres limiteurs du site, qui comptent
 * encore une IPv6 par adresse entière, la réutiliseront (limite acceptée, plan T12).
 *
 * Sans état : une fonction de ses octets, qui rend `null` pour une IPv4 elle-même,
 * pour une IPv6 qui n'en porte pas et pour une longueur qui n'est pas celle d'une
 * adresse IPv6.
 */
final readonly class EmbeddedIpv4
{
    private const int IPV6_LENGTH = 16;

    /** Les dix octets nuls puis `ffff` d'une IPv4 mappée. */
    private const string MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    /** `64:ff9b::/96`, douze octets. */
    private const string TRANSLATION_PREFIX = "\0\x64\xFF\x9B\0\0\0\0\0\0\0\0";

    private const string SIX_TO_FOUR_PREFIX = "\x20\x02";

    /** Les douze octets nuls d'une IPv4 compatible. */
    private const string COMPATIBLE_PREFIX = "\0\0\0\0\0\0\0\0\0\0\0\0";

    /** Les quatre derniers octets : l'IPv4 mappée, traduite ou compatible. */
    private const int TRAILING_OFFSET = 12;

    /** Les octets 2 à 5 d'une adresse 6to4. */
    private const int SIX_TO_FOUR_OFFSET = 2;

    private const int IPV4_LENGTH = 4;

    private function __construct(
        public EmbeddedIpv4Form $form,
        /** L'IPv4 portée, en notation pointée. */
        public string $address,
    ) {
    }

    /**
     * @param string $packed les seize octets d'une adresse IPv6 (`inet_pton()`)
     */
    public static function tryFromPacked(string $packed): ?self
    {
        if (self::IPV6_LENGTH !== \strlen($packed)) {
            return null;
        }

        return match (true) {
            // Mappée avant compatible : leurs préfixes ne se recouvrent pas
            // (octets 10 et 11), l'ordre n'est qu'une lecture du plus précis au plus large.
            str_starts_with($packed, self::MAPPED_PREFIX) => self::trailing(EmbeddedIpv4Form::Mapped, $packed),
            str_starts_with($packed, self::TRANSLATION_PREFIX) => self::trailing(EmbeddedIpv4Form::Translated, $packed),
            str_starts_with($packed, self::SIX_TO_FOUR_PREFIX) => new self(
                EmbeddedIpv4Form::SixToFour,
                (string) inet_ntop(substr($packed, self::SIX_TO_FOUR_OFFSET, self::IPV4_LENGTH)),
            ),
            str_starts_with($packed, self::COMPATIBLE_PREFIX) => self::trailing(EmbeddedIpv4Form::Compatible, $packed),
            default => null,
        };
    }

    private static function trailing(EmbeddedIpv4Form $form, string $packed): self
    {
        return new self($form, (string) inet_ntop(substr($packed, self::TRAILING_OFFSET, self::IPV4_LENGTH)));
    }
}
