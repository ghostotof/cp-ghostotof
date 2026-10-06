<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

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

    /**
     * Le compte est chargé avec le jeton, par jointure explicite (issue #356) :
     * en proxy paresseux, seul le chemin « lien déjà consommé », qui journalise
     * le compte, payait une requête de plus que le chemin « expiré » — un
     * écart de temps qui distinguait les deux cas derrière le même 410.
     */
    public function findOneByTokenHash(string $tokenHash): ?PasswordSetupToken
    {
        /** @var PasswordSetupToken|null */
        return $this->createQueryBuilder('token')
            ->addSelect('user')
            ->innerJoin('token.user', 'user')
            ->where('token.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * `UPDATE … WHERE used_at IS NULL` : la base, pas l'entité en mémoire,
     * décide qui consomme. Le nombre de lignes touchées dit si c'est nous.
     */
    public function claim(PasswordSetupToken $token, \DateTimeImmutable $usedAt): bool
    {
        $claimed = $this->createQueryBuilder('token')
            ->update()
            ->set('token.usedAt', ':usedAt')
            ->where('token.id = :id')
            ->andWhere('token.usedAt IS NULL')
            ->setParameter('usedAt', $usedAt, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $token->getId(), UuidType::NAME)
            ->getQuery()
            ->execute();

        return 1 === $claimed;
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
