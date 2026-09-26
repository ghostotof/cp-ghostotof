<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Repository;

use App\Security\User\Domain\Entity\CpgUser;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) dont dépend la couche Application (CpgUserRegistrar) :
 * elle ne connaît jamais Doctrine directement. L'implémentation concrète vit
 * dans Infrastructure\Doctrine\CpgUserRepository.
 */
interface CpgUserRepositoryInterface
{
    public function findOneByUsername(string $username): ?CpgUser;

    public function findOneByEmail(string $email): ?CpgUser;

    public function findOneById(Uuid $id): ?CpgUser;

    /**
     * @return list<CpgUser>
     */
    public function findAll(): array;

    /**
     * Comptes en attente de définition de mot de passe
     * (CpgUser::isAwaitingPasswordSetup()) invités avant $threshold, du plus
     * ancien au plus récent. Alimente PendingInvitationPurger (issue #238) :
     * "en attente depuis trop longtemps" se détermine en base, jamais par un
     * findAll() filtré en PHP. Round de correction (I2) : renommée depuis
     * findPendingActivationInvitedBefore() — le prédicat exige en plus un mot
     * de passe vide, pour exclure un compte invité dont le mot de passe a été
     * posé depuis le backoffice (CpgUserAdministrator::changePassword(), sans
     * jamais markActivated()) et qui se connecte donc déjà.
     *
     * @return list<CpgUser>
     */
    public function findAwaitingPasswordSetupInvitedBefore(\DateTimeImmutable $threshold): array;

    /**
     * Nombre d'utilisateurs possédant le rôle donné (rôles implicites inclus,
     * cf. CpgUser::getRoles()). Utilisé par la garde anti-lockout du dernier
     * ROLE_SUPER (cf. CpgUserAdministrator::delete).
     */
    public function countByRole(string $role): int;

    public function save(CpgUser $user): void;

    public function remove(CpgUser $user): void;
}
