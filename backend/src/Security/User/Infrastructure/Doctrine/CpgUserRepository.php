<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use SensitiveParameter;
use Symfony\Bridge\Doctrine\Security\User\UserLoaderInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CpgUser>
 */
class CpgUserRepository extends ServiceEntityRepository implements CpgUserRepositoryInterface, PasswordUpgraderInterface, UserLoaderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CpgUser::class);
    }

    public function findOneByUsername(#[SensitiveParameter] string $username): ?CpgUser
    {
        return $this->findOneBy(['username' => $username]);
    }

    /**
     * Chargeur du fournisseur `cpg_users` (security.yaml), à la place de son
     * option `property: username` (issue #418).
     *
     * Le fournisseur `entity` de Doctrine, quand il cherche lui-même par
     * propriété, lève `UserNotFoundException('User "<identifiant>" not
     * found.')` ;
     * {@see \Symfony\Component\Security\Http\Authentication\AuthenticatorManager}
     * journalise cette exception en `info` sur le canal `security`, que la
     * production écrit (monolog.yaml). Or
     * l'identifiant tenté est souvent un mot de passe tapé dans le mauvais
     * champ. Ici l'exception est levée avant que le fournisseur ne construise
     * la sienne, avec un message qui ne cite rien ; l'identifiant reste porté
     * par `setUserIdentifier()`, que le formateur JSON de Monolog n'écrit pas
     * (il ne sérialise d'une exception que sa classe, son message, son code
     * et son fichier).
     *
     * Un identifiant hors de `CpgUser::USERNAME_PATTERN` ne peut désigner
     * aucun compte — le constructeur l'impose — et n'est donc pas cherché
     * (relecture de #418). Sans ce refus, PostgreSQL, qui reçoit les
     * paramètres texte comme des chaînes C, coupait `jane\0…` au NUL et
     * chargeait `jane`, pendant que `login_throttling` comptait chaque
     * suffixe à part ; un octet hors UTF-8 faisait échouer la requête.
     */
    public function loadUserByIdentifier(#[SensitiveParameter] string $identifier): CpgUser
    {
        $user = 1 === preg_match(CpgUser::USERNAME_PATTERN, $identifier) ? $this->findOneByUsername($identifier) : null;

        if (null === $user) {
            $exception = new UserNotFoundException('Unknown user identifier.');
            $exception->setUserIdentifier($identifier);

            throw $exception;
        }

        return $user;
    }

    public function findOneByEmail(string $email): ?CpgUser
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findOneById(Uuid $id): ?CpgUser
    {
        return $this->find($id);
    }

    public function findAll(): array
    {
        return $this->findBy([], ['username' => 'ASC']);
    }

    public function findAwaitingPasswordSetupInvitedBefore(DateTimeImmutable $threshold): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.invitedAt IS NOT NULL')
            ->andWhere('u.activatedAt IS NULL')
            ->andWhere('u.invitedAt < :threshold')
            // Round de correction (I2, issue #238) : un mot de passe non vide
            // veut dire qu'un ROLE_SUPER l'a posé depuis le backoffice
            // (CpgUserAdministrator::changePassword(), qui ne marque jamais
            // activatedAt) — le compte se connecte déjà et ne doit plus
            // ressortir ici, même s'il reste isPendingActivation() vrai.
            ->andWhere("u.password = ''")
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->orderBy('u.invitedAt', 'ASC')
            ->getQuery()
            ->getResult();
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

    /**
     * Un `UPDATE … RETURNING` : PostgreSQL verrouille la ligne le temps de
     * l'écriture, donc deux relances concurrentes s'enchaînent et lisent
     * chacune la génération que l'autre vient de poser — là où avancer le
     * compteur en mémoire puis l'écrire en valeur absolue perdait l'une des
     * deux (issue #435).
     */
    public function advanceInvitationGeneration(CpgUser $user, DateTimeImmutable $invitedAt): int
    {
        $generation = $this->getEntityManager()->getConnection()->fetchOne(
            'UPDATE cpg_user SET invitation_generation = invitation_generation + 1, invited_at = :invitedAt WHERE id = :id RETURNING invitation_generation',
            ['invitedAt' => $invitedAt, 'id' => $user->getId()],
            ['invitedAt' => Types::DATETIMETZ_IMMUTABLE, 'id' => UuidType::NAME],
        );

        if (!\is_int($generation)) {
            throw CpgUserNotFoundException::forId($user->getId());
        }

        return $generation;
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
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->save($user);
    }
}
