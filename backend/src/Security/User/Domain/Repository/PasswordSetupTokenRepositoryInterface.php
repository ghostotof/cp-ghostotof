<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Repository;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use DateTimeImmutable;

/**
 * Abstraction (DIP) dont dépendent CpgUserInviter (création du jeton) et
 * PasswordSetupService (validation / consommation). L'implémentation concrète
 * vit dans Infrastructure\Doctrine\PasswordSetupTokenRepository.
 */
interface PasswordSetupTokenRepositoryInterface
{
    public function save(PasswordSetupToken $token): void;

    public function remove(PasswordSetupToken $token): void;

    public function findOneByTokenHash(string $tokenHash): ?PasswordSetupToken;

    /**
     * Marque le jeton consommé si et seulement s'il ne l'était pas encore, en
     * une écriture atomique (issue #356) : de deux requêtes qui présentent le
     * même lien au même instant, une seule l'emporte. Rend `false` à la
     * perdante, sans rien écrire.
     */
    public function claim(PasswordSetupToken $token, DateTimeImmutable $usedAt): bool;

    /**
     * Supprime tous les jetons de l'utilisateur : appelé avant de régénérer un
     * jeton (invitation renvoyée) pour qu'un seul reste valide à la fois.
     */
    public function deleteForUser(CpgUser $user): void;
}
