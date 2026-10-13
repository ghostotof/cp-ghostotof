<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Repository;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use DateTimeImmutable;
use SensitiveParameter;
use Symfony\Component\Uid\Uuid;

/**
 * Abstraction (DIP) dont dépend la couche Application (CpgUserRegistrar) :
 * elle ne connaît jamais Doctrine directement. L'implémentation concrète vit
 * dans Infrastructure\Doctrine\CpgUserRepository.
 */
interface CpgUserRepositoryInterface
{
    /**
     * @param string $username au login, l'identifiant tenté — possiblement un
     *                         mot de passe tapé dans le mauvais champ (#418)
     */
    public function findOneByUsername(#[SensitiveParameter] string $username): ?CpgUser;

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
    public function findAwaitingPasswordSetupInvitedBefore(DateTimeImmutable $threshold): array;

    /**
     * Nombre de comptes ROLE_SUPER **capables de se connecter** (mot de passe
     * défini), `$except` exclu : la garde anti-lockout du dernier ROLE_SUPER
     * (CpgUserAdministrator::delete(), CpgUserRoleAdministrator::setSuperAdmin()),
     * appelée sous le verrou d'administration (issue #551). Compté **en
     * base**, jamais sur un `findAll()` filtré en PHP : la carte d'identité
     * tient l'acteur et la cible tels que la requête les a chargés, avant
     * l'écriture concurrente que le verrou vient d'attendre. Un ROLE_SUPER
     * encore en attente d'activation (mot de passe vide) ne compte pas : il ne
     * peut pas se connecter, il ne garde pas le backoffice ouvert.
     */
    public function countOtherSuperAdminsAbleToSignIn(CpgUser $except): int;

    public function save(CpgUser $user): void;

    /**
     * Enregistre un compte **nouveau**, et nomme l'unicité qu'une écriture
     * concurrente lui aurait prise entre sa vérification et l'enregistrement
     * (issue #364) — `cpg_user` en porte deux. Comme tout `flush()` refusé,
     * le refus ferme l'EntityManager.
     *
     * @throws EmailAlreadyUsedException    si l'adresse a été prise entre-temps
     * @throws UsernameAlreadyUsedException si l'identifiant a été pris entre-temps
     */
    public function add(CpgUser $user): void;

    /**
     * Avance la génération d'invitation du compte et pose `invited_at`, en une
     * seule écriture atomique (issue #435) : deux relances concurrentes
     * reçoivent deux générations distinctes, quel que soit l'état que chacune
     * avait chargé, et seulement si le compte est toujours en attente
     * d'activation. Rend la génération ainsi posée.
     *
     * @throws CpgUserNotFoundException              si le compte a disparu entre-temps
     * @throws AccountNotAwaitingActivationException si le compte a été activé entre-temps
     */
    public function advanceInvitationGeneration(CpgUser $user, DateTimeImmutable $invitedAt): int;

    public function remove(CpgUser $user): void;

    /**
     * Défait une invitation que la file n'a pas prise (issue #485) : supprime
     * le compte par son identifiant, jamais s'il a été activé entre-temps.
     * Chemin d'échec : ne dépend ni de l'unité de travail ni d'un `flush()`
     * global. Un compte déjà supprimé n'est pas une erreur.
     *
     * Le compte dont la ligne a été supprimée n'est plus géré, et le sauver
     * de nouveau est refusé (issue #569) ; un compte épargné reste géré, de
     * même qu'un compte déjà supprimé : la suppression n'a touché aucune ligne,
     * et rien ne le distingue d'un compte épargné.
     */
    public function removePendingInvitation(CpgUser $user): void;
}
