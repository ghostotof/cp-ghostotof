<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Shared\Application\Security\RevokedAccountMcpConnections;

/**
 * Ce que l'écriture d'un rôle, faite sous le verrou d'administration, rend à
 * {@see CpgUserRoleAdministrator::setSuperAdmin()} pour qu'il agisse **après**
 * le COMMIT (#650, R5a) : si le rôle a changé (la ligne `role-changed` ne
 * s'écrit que pour un changement effectif), et ce que la perte du palier de
 * confiance a révoqué comme connexions MCP, à auditer ensuite.
 *
 * Un type nommé plutôt que le tableau `[bool, ?RevokedAccountMcpConnections]`
 * que la fermeture rendait : deux valeurs de natures différentes qu'un
 * appelant lisait par position. Trois états seulement, chacun par son
 * constructeur : rien changé ; rôle changé sans connexion coupée ; rôle changé
 * et connexions coupées.
 */
final readonly class RoleChangeOutcome
{
    private function __construct(
        public bool $changed,
        public ?RevokedAccountMcpConnections $revoked,
    ) {
    }

    /** Déjà dans l'état demandé : rien d'écrit, rien à auditer. */
    public static function unchanged(): self
    {
        return new self(false, null);
    }

    /**
     * Le rôle a changé. `$revoked` est `null` quand aucun palier n'a été
     * perdu (promotion, rétrogradation d'un compte qui garde `ROLE_TRUSTED`).
     */
    public static function changed(?RevokedAccountMcpConnections $revoked = null): self
    {
        return new self(true, $revoked);
    }
}
