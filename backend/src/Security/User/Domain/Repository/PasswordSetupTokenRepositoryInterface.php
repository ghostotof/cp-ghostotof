<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Repository;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\ValueObject\TokenClaim;
use DateTimeImmutable;

/**
 * Abstraction (DIP) dont dépendent CpgUserInviter (création du jeton) et
 * PasswordSetupService (validation / consommation). L'implémentation concrète
 * vit dans Infrastructure\Doctrine\PasswordSetupTokenRepository.
 */
interface PasswordSetupTokenRepositoryInterface
{
    public function save(PasswordSetupToken $token): void;

    /**
     * Supprime ce jeton-là, et lui seul : celui d'une invitation dont l'e-mail
     * n'est pas parti (issue #425). Jamais un jeton consommé.
     *
     * Le jeton dont la ligne a été supprimée n'est plus géré, et le sauver de
     * nouveau est refusé (issue #569) ; un jeton épargné reste géré. Une ligne
     * déjà disparue n'est pas une erreur, et son instance reste gérée elle
     * aussi : rien ne la distingue d'une ligne épargnée (la suppression n'a
     * touché aucune ligne).
     */
    public function remove(PasswordSetupToken $token): void;

    public function findOneByTokenHash(string $tokenHash): ?PasswordSetupToken;

    /**
     * Marque le jeton consommé si et seulement s'il ne l'était pas encore, en
     * une écriture atomique (issue #356) : de deux requêtes qui présentent le
     * même lien au même instant, une seule l'emporte. Rend `false` à la
     * perdante, sans rien écrire. L'expiration est jugée dans la même
     * écriture (issue #447) : un jeton expiré à `$usedAt` n'est pas consommé.
     * Un échec dit sa cause (TokenClaim, issue #458) : déjà consommé, expiré,
     * ou disparu.
     */
    public function claim(PasswordSetupToken $token, DateTimeImmutable $usedAt): TokenClaim;

    /**
     * Supprime tous les jetons de l'utilisateur : appelé avant de régénérer un
     * jeton (invitation renvoyée) pour qu'un seul reste valide à la fois.
     */
    public function deleteForUser(CpgUser $user): void;

    /**
     * Supprime les jetons encore utilisables du compte, à son activation
     * (issue #458) : un compte activé n'en garde aucun. Le jeton consommé
     * reste, pour qu'un lien déjà utilisé qui revient soit journalisé comme
     * un rejeu (`replayed`), pas comme un lien inconnu (`rejected`) — la
     * réponse, elle, est la même (issue #362).
     */
    public function deleteUnusedForUser(CpgUser $user): void;
}
