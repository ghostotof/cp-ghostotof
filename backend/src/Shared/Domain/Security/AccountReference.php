<?php

declare(strict_types=1);

namespace App\Shared\Domain\Security;

/**
 * Ce par quoi un compte se désigne hors de `Security/User` : son UUID, jamais
 * son identifiant de connexion (issue #363).
 *
 * Pour un compte sur invitation, l'identifiant de connexion est dérivé de la partie
 * locale de l'adresse (ADR 0001 D2) — souvent un prénom et un nom. Les
 * journaux (`security_audit`, `security`, `ai_usage`) et les clés des quotas
 * par compte écrivent donc l'UUID, que seule la base relie à une personne, et
 * plus rien une fois le compte supprimé.
 *
 * Une interface dans `Shared` plutôt que `CpgUser` lu directement : `Ai`, qui
 * clé ses quotas sur le compte, ne dépend d'aucun autre contexte (comme
 * `Contact`, cf. ThrottledRequestAuditListener), et c'est le seul fait du
 * compte dont il a besoin. Le sujet du palier de base
 * ({@see \App\Security\User\Domain\ValueObject\GuestUser}) ne l'implémente
 * pas : il n'est pas un compte, et son marqueur `guest-…`, aléatoire, ne
 * désigne personne.
 */
interface AccountReference
{
    /** UUID du compte, forme RFC 4122 (minuscules). */
    public function accountId(): string;
}
