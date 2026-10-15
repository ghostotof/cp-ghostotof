<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Security\User\Domain\ValueObject\BaseAccessUser;
use App\Shared\Domain\Security\AccountReference;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Comment un journal désigne un utilisateur authentifié (issue #363), écrit
 * une fois pour le sujet et l'auteur de SecurityAuditLogger comme pour le
 * jeton que réécrit SecurityChannelAccountRedactor :
 *
 *  - un compte ({@see AccountReference}) par son UUID, sous `userId` —
 *    jamais par son identifiant de connexion, dérivé de l'adresse pour un
 *    invité (ADR 0001 D2) ;
 *  - le sujet du palier de base ({@see BaseAccessUser}) par son marqueur `guest-…`,
 *    aléatoire, sous `user` ;
 *  - tout autre utilisateur, par rien : aucun fournisseur n'en produit, et
 *    on ne sait pas ce que son identifiant contient.
 */
final readonly class PrincipalDesignation
{
    private function __construct(
        /** `userId` ou `user` */
        public string $key,
        public string $value,
    ) {
    }

    public static function of(UserInterface $user): ?self
    {
        return match (true) {
            $user instanceof AccountReference => new self('userId', $user->accountId()),
            $user instanceof BaseAccessUser => new self('user', $user->getUserIdentifier()),
            default => null,
        };
    }
}
