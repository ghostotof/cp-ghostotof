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

        $user->setRoles($grant ? [CpgUser::ROLE_SUPER] : $this->rolesAfterDemotion($user));
        $this->cpgUserRepository->save($user);
    }

    /**
     * ADR 0003 D1 (issue #78, pt 3). ROLE_SUPER englobe ROLE_TRUSTED via la
     * role_hierarchy, mais ROLE_TRUSTED ne s'accorde que nominativement : par
     * l'invitation, qui lie l'octroi à une adresse e-mail, donc à une personne.
     * Un compte invité retrouve donc son palier réel à la rétrogradation — il
     * n'y a pas de perte de confiance implicite dans un acte administratif.
     * Un compte CLI, lui, n'a pas d'e-mail : personne ne l'a jamais accordé, et
     * la CLI refuse justement `--role ROLE_TRUSTED` (Task 12). Le promouvoir
     * puis le rétrograder ne doit pas être une voie détournée vers le CV : il
     * retombe au palier de base (ROLE_USER, ajouté par getRoles()).
     *
     * @return list<string>
     */
    private function rolesAfterDemotion(CpgUser $user): array
    {
        return null !== $user->getEmail() ? [CpgUser::ROLE_TRUSTED] : [];
    }
}
