<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use LogicException;

/**
 * league a achevé une demande d'autorisation sans rendre de redirection vers
 * le client (#647) : son contrat est rompu — un accord rend toujours un 302
 * avec `Location`, un refus lève toujours `access_denied` vers la redirection
 * validée. Un défaut du serveur (une montée de version de league), sans
 * entrée `framework.exceptions` : un 500 `critical`.
 */
final class AuthorizationResponseWithoutRedirectException extends LogicException
{
    public static function afterApproval(): self
    {
        return new self('league a accordé la demande d\'autorisation sans rendre de redirection vers le client.');
    }

    public static function afterDenial(): self
    {
        return new self('league a refusé la demande d\'autorisation sans rendre de redirection vers le client.');
    }
}
