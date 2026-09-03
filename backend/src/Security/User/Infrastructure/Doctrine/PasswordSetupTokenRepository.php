<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PasswordSetupToken>
 */
class PasswordSetupTokenRepository extends ServiceEntityRepository implements PasswordSetupTokenRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordSetupToken::class);
    }

    public function save(PasswordSetupToken $token): void
    {
        $this->getEntityManager()->persist($token);
        $this->getEntityManager()->flush();
    }

    public function remove(PasswordSetupToken $token): void
    {
        $this->getEntityManager()->remove($token);
        $this->getEntityManager()->flush();
    }

    public function findOneByTokenHash(string $tokenHash): ?PasswordSetupToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function deleteForUser(CpgUser $user): void
    {
        $this->createQueryBuilder('token')
            ->delete()
            ->where('token.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
