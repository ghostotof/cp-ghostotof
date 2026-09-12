<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CannotDemoteLastSuperAdminException;
use App\Security\User\Domain\Exception\CannotModifyOwnRolesException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;

final readonly class CpgUserRoleAdministrator implements CpgUserRoleAdministratorInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
    ) {
    }

    public function setSuperAdmin(int $id, bool $grant, CpgUser $actingUser): void
    {
        if ($id === $actingUser->getId()) {
            throw CannotModifyOwnRolesException::forUsername($actingUser->getUsername());
        }

        $user = $this->cpgUserRepository->findOneById($id);

        if (null === $user) {
            throw CpgUserNotFoundException::forId($id);
        }

        $isSuper = \in_array(CpgUser::ROLE_SUPER, $user->getRoles(), true);

        if ($grant === $isSuper) {
            // Déjà dans l'état demandé : rien à faire (et la garde du dernier
            // super-admin ne doit pas être consultée pour une cible non-super).
            return;
        }

        // Garde anti-lockout (pendant de CpgUserAdministrator::delete) : `<= 1`
        // par prudence, jamais 0 ici puisque $user en fait partie.
        if (!$grant && $this->cpgUserRepository->countByRole(CpgUser::ROLE_SUPER) <= 1) {
            throw CannotDemoteLastSuperAdminException::forUsername($user->getUsername());
        }

        // ADR 0003 D1 : ROLE_SUPER implique déjà ROLE_TRUSTED via la
        // role_hierarchy — une rétrogradation ramène donc au palier réel de
        // la personne (ROLE_TRUSTED), jamais au palier de base. Il n'y a pas
        // de perte de confiance implicite lors d'une simple rétrogradation
        // administrative.
        $user->setRoles($grant ? [CpgUser::ROLE_SUPER] : [CpgUser::ROLE_TRUSTED]);
        $this->cpgUserRepository->save($user);
    }
}
