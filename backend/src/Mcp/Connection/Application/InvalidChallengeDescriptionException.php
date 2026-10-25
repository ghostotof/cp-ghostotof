<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use InvalidArgumentException;

/**
 * Un `error_description` de défi Bearer hors de l'alphabet que RFC 6749 A.2
 * lui fixe (`%x20-21 / %x23-5B / %x5D-7E`, au moins un caractère), repris par
 * RFC 6750 §3 (#648). Un guillemet fermerait la chaîne entre guillemets de
 * RFC 7235, une barre oblique inverse l'échapperait, un retour à la ligne
 * couperait l'en-tête.
 *
 * La description est écrite par le serveur, jamais par le client : un refus
 * est une faute du serveur, sans entrée de journalisation propre. Le message
 * ne cite pas la valeur refusée.
 *
 * Un seul chemin la lève, BearerChallenge::insufficientScope(), et un seul
 * appelant : l'outil nominatif (ADR 0006 D3), pour une constante —
 * GetNominativeCorpusToolTest lit le défi qu'elle produit, la faute ne
 * survivrait pas à la suite. Levée dans l'outil, elle n'atteint jamais le
 * noyau HTTP : le `CallToolHandler` du SDK 0.8.1 attrape tout `Throwable`,
 * l'écrit en `error` sur le canal `mcp` (« Unhandled error during tool
 * execution », que SdkChannelRedactor ne range pas parmi les fautes du
 * client) et répond **HTTP 200**, une erreur JSON-RPC `-32603` « Error while
 * executing tool ». L'authentificateur et l'autorisation différée n'y
 * mènent pas : leurs défis (`refusedToken()`, `missingToken()`) ne portent
 * aucune description et ne lèvent rien.
 */
final class InvalidChallengeDescriptionException extends InvalidArgumentException
{
    public static function outsideTheRfcCharacterSet(): self
    {
        return new self('The error_description of a Bearer challenge must be 1 or more characters of %x20-21 / %x23-5B / %x5D-7E (RFC 6749 A.2).');
    }
}
