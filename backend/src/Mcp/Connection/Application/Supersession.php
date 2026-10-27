<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\ValueObject\RevokedConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Le remplacement qu'un premier échange de code a écrit (#726) : la connexion
 * gardée — celle du code échangé — et les autres connexions actives du même
 * couple compte/client que l'échange a révoquées, cause `superseded`.
 *
 * La connexion gardée voyage avec les révoquées pour que la ligne d'audit de
 * chacune la nomme (`supersededBy`, constat B3 de l'audit de #726) : écrite
 * sur la requête de l'échange, et non plus sur celle du consentement, rien
 * d'autre ne relie la coupure à l'accord qui l'a causée. Interne au point de
 * jeton, jamais persisté ni exposé.
 */
final readonly class Supersession
{
    /**
     * @param Uuid                    $kept    la connexion que le code échangé désigne, celle qui remplace
     * @param list<RevokedConnection> $revoked les autres connexions actives du couple que l'échange a révoquées ;
     *                                         vide pour un couple sans autre connexion active
     */
    public function __construct(
        public Uuid $kept,
        public array $revoked,
    ) {
    }
}
