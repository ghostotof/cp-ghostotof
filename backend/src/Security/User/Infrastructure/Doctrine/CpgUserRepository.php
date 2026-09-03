<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<CpgUser>
 */
class CpgUserRepository extends ServiceEntityRepository implements CpgUserRepositoryInterface, PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CpgUser::class);
    }

    public function findOneByUsername(string $username): ?CpgUser
    {
        return $this->findOneBy(['username' => $username]);
    }

    public function findOneByEmail(string $email): ?CpgUser
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findOneById(int $id): ?CpgUser
    {
        return $this->find($id);
    }

    public function findAll(): array
    {
        return $this->findBy([], ['username' => 'ASC']);
    }

    /**
     * `roles` est une colonne JSON : plutôt qu'un `WHERE` portable
     * hasardeux sur du JSON, on filtre en PHP. La table `cpg_user` n'est
     * alimentée que par la commande CLI (aucune inscription publique), son
     * volume reste négligeable — le coût du chargement complet est ici sans
     * enjeu de performance.
     */
    public function countByRole(string $role): int
    {
        return \count(array_filter(
            $this->findAll(),
            static fn (CpgUser $user): bool => \in_array($role, $user->getRoles(), true),
        ));
    }

    public function save(CpgUser $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function remove(CpgUser $user): void
    {
        $this->getEntityManager()->remove($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Ré-encode et persiste le mot de passe si l'algorithme de hash configuré a changé.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof CpgUser) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->save($user);
    }
}
