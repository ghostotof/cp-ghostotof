<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use LogicException;

/**
 * Un chemin de league a voulu la clé de signature des jetons d'accès : ceux
 * de ce serveur sont opaques (#647), aucune clé ne les signe. Défaut de
 * câblage — un JWT demandé, un ResourceServer de league branché —, jamais une
 * erreur client : aucune entrée `framework.exceptions`, un 500 `critical`.
 */
final class AccessTokensAreNotSignedException extends LogicException
{
    public static function noSigningKey(): self
    {
        return new self('Les jetons d\'accès du serveur d\'autorisation MCP sont opaques : aucune clé de signature n\'existe, et aucun chemin ne doit en demander une.');
    }
}
