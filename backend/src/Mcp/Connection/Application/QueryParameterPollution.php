<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

/**
 * La pollution de paramètres d'une requête d'autorisation (#647) : ce que PHP
 * lit d'une requête n'est pas ce qu'un autre analyseur en lit.
 *
 * - **Répétition** : PHP garde la **dernière** occurrence (`client_id=a&client_id=b`
 *   → `b`), `URLSearchParams.get()` la **première**, comme bien des clients.
 * - **Réécriture des noms** : PHP remplace l'espace, le point et un `[` non
 *   refermé par `_` (`client.id`, `client id`, `client[id` → `client_id`), ignore
 *   les espaces de tête et coupe le nom au premier octet nul ; un autre
 *   analyseur garde le nom tel quel.
 *
 * Une demande validée sous une lecture pourrait donc s'afficher, ou se
 * rejouer, sous une autre. RFC 6749 §4.1.2.1 tient un paramètre répété pour
 * une demande malformée : ce sont des refus, jamais des corrections.
 *
 * La lecture se fait sur la requête **brute** (`QUERY_STRING`, que le pont
 * PSR-7 recopie dans l'URI), nom décodé comme PHP le décode (`+` → espace,
 * `%xx`), puis réécrit comme PHP le réécrit. La réécriture est volontairement
 * un peu plus large que celle de PHP (tout espace, point ou `[` du nom quand
 * aucun `]` ne le referme) : un nom attendu atteint par excès est refusé, ce
 * qui ne coûte rien à un client honnête, qui n'écrit jamais ces noms-là.
 */
final class QueryParameterPollution
{
    private function __construct()
    {
    }

    /**
     * Le premier des noms surveillés que la requête brute répète, ou n'atteint
     * qu'au prix d'une réécriture par PHP (`client.id`, `client_id[]`,
     * ` client_id`, `client_id%00x`) ; `null` si aucun.
     *
     * @param list<string> $watched
     */
    public static function firstPollutedName(string $rawQuery, array $watched): ?string
    {
        $seen = [];

        foreach (explode('&', $rawQuery) as $pair) {
            if ('' === $pair) {
                continue;
            }

            $name = urldecode(explode('=', $pair, 2)[0]);
            $phpName = self::asPhpReadsIt($name);

            if (!\in_array($phpName, $watched, true)) {
                continue;
            }

            if ($phpName !== $name || isset($seen[$phpName])) {
                return $phpName;
            }

            $seen[$phpName] = true;
        }

        return null;
    }

    /**
     * Le nom de variable que PHP tire d'un nom de paramètre
     * (`php_register_variable_ex()`) : coupé au premier octet nul, espaces de
     * tête retirés ; un `[` suivi d'un `]` ouvre un tableau — le nom est ce qui
     * le précède ; sinon espace, point et `[` deviennent `_`.
     */
    private static function asPhpReadsIt(string $name): string
    {
        $name = ltrim(explode("\0", $name, 2)[0], ' ');
        $bracket = strpos($name, '[');

        if (false !== $bracket && str_contains(substr($name, $bracket), ']')) {
            $name = substr($name, 0, $bracket);
        }

        return strtr($name, [' ' => '_', '.' => '_', '[' => '_']);
    }
}
