<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use LogicException;
use Throwable;

/**
 * league a achevé une demande d'autorisation autrement que son contrat le dit
 * (#647) : un accord rend toujours un 302 avec `Location` ; un refus lève
 * toujours `access_denied` vers la redirection validée. Rien d'autre ne part
 * vers le client — ni une autre erreur OAuth, même munie d'une redirection,
 * ni une réponse sans redirection. Un défaut du serveur (une montée de version
 * de league), sans entrée `framework.exceptions` : un 500 `critical`.
 */
final class UnexpectedAuthorizationResponseException extends LogicException
{
    public static function approvalWithoutRedirect(): self
    {
        return new self('league a accordé la demande d\'autorisation sans rendre de redirection vers le client.');
    }

    public static function denialWithoutRedirect(): self
    {
        return new self('league a refusé la demande d\'autorisation sans rendre de redirection vers le client.');
    }

    /**
     * Au refus, league a levé autre chose qu'`access_denied` : l'erreur est
     * gardée en `previous` pour le journal, jamais renvoyée au client.
     */
    public static function denialOtherThanAccessDenied(Throwable $raised): self
    {
        return new self('league a répondu au refus de la demande d\'autorisation par une autre erreur qu\'access_denied.', 0, $raised);
    }
}
