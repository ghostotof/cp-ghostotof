<?php

declare(strict_types=1);

namespace App\Shared\Application\Security;

/**
 * Pourquoi un compte perd ses connexions MCP d'un coup (#650) : un ensemble
 * **fermé** de raisons, jamais une cause générique.
 *
 * `Security/User` nomme la raison qu'il connaît (le mot de passe vient de
 * changer, le compte vient de perdre `ROLE_TRUSTED`), et `Mcp/Connection` la
 * traduit en sa propre cause de révocation par un `match` exhaustif : une
 * raison ajoutée ici sans sa cause là-bas est refusée par PHPStan. Les valeurs
 * sont les mots stables des causes `password-changed` et
 * `trusted-tier-withdrawn` du journal d'audit.
 *
 * Un port de `Shared` plutôt qu'une classe de `Mcp` importée par `Security` :
 * `Security` ne dépend d'aucune classe de `Mcp`
 * (`.claude/rules/security-authentication-audit.md`).
 */
enum AccountAccessWithdrawal: string
{
    /** Le mot de passe du compte a changé (`CpgUserAdministrator::changePassword()`). */
    case PasswordChanged = 'password-changed';

    /** Le compte a perdu le palier de confiance (`CpgUserRoleAdministrator`, tâche 6 du plan de #650). */
    case TrustedTierWithdrawn = 'trusted-tier-withdrawn';
}
