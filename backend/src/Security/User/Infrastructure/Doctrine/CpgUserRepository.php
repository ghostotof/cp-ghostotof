<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Shared\Infrastructure\Doctrine\VanishedEntities;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
    public function __construct(
        ManagerRegistry $registry,
        private readonly VanishedEntities $vanishedEntities,
    ) {
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
            ->andWhere('u.password = :noPassword')
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->setParameter('noPassword', CpgUser::NO_PASSWORD)
            ->orderBy('u.invitedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * En SQL, sur la colonne `roles` (JSON, lue en `jsonb` pour l'opérateur de
     * contenance `@>`) : jusqu'à #551, un `findAll()` filtré en PHP rendait les
     * instances de la carte d'identité, donc l'acteur et la cible tels que la
     * requête les avait chargés — un comptage périmé sous le verrou même. Le
     * rôle est lié en paramètre JSON, jamais interpolé. Un compte sans mot de
     * passe ({@see CpgUser::NO_PASSWORD}, `CpgUser::hasPassword()` faux) ne peut
     * pas se connecter et ne compte pas.
     */
    public function countOtherSuperAdminsAbleToSignIn(CpgUser $except): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM cpg_user'
            .' WHERE id <> :id AND CAST(roles AS jsonb) @> CAST(:role AS jsonb) AND password <> :noPassword',
            ['id' => $except->getId(), 'role' => json_encode([CpgUser::ROLE_SUPER], \JSON_THROW_ON_ERROR), 'noPassword' => CpgUser::NO_PASSWORD],
            ['id' => UuidType::NAME],
        );

        // Un COUNT(*) est un entier pour pdo_pgsql, une chaîne pour un pilote
        // qui textualise ; la conversion ne dépend pas du pilote, et
        // l'assertion ne sert qu'à l'analyse statique.
        \assert(is_numeric($count));

        return (int) $count;
    }

    public function save(CpgUser $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * La traduction vit ici, seul endroit qui connaisse la base : ni les
     * noms d'index ni le format des messages de PostgreSQL ne remontent dans
     * la couche Application (issue #364).
     */
    public function add(CpgUser $user): void
    {
        try {
            $this->save($user);
        } catch (UniqueConstraintViolationException $violation) {
            throw CpgUserUniqueViolation::refusalFor($violation);
        }
    }

    /**
     * Un `UPDATE … RETURNING` : PostgreSQL verrouille la ligne le temps de
     * l'écriture, donc deux relances concurrentes s'enchaînent et lisent
     * chacune la génération que l'autre vient de poser — là où avancer le
     * compteur en mémoire puis l'écrire en valeur absolue perdait l'une des
     * deux (issue #435). La condition « en attente d'activation »
     * (CpgUser::isPendingActivation()) est portée par l'écriture elle-même :
     * un compte activé entre son chargement et la relance n'est pas relancé.
     *
     * L'unité de travail reçoit les valeurs écrites pour état d'origine : sans
     * quoi elle garderait l'ancienne génération, et un `flush()` ultérieur
     * réécrirait celle de l'entité en valeur absolue, par-dessus une relance
     * concurrente.
     */
    public function advanceInvitationGeneration(CpgUser $user, DateTimeImmutable $invitedAt): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $generation = $connection->fetchOne(
            'UPDATE cpg_user SET invitation_generation = invitation_generation + 1, invited_at = :invitedAt'
            .' WHERE id = :id AND invited_at IS NOT NULL AND activated_at IS NULL RETURNING invitation_generation',
            ['invitedAt' => $invitedAt, 'id' => $user->getId()],
            ['invitedAt' => Types::DATETIMETZ_IMMUTABLE, 'id' => UuidType::NAME],
        );

        if (!\is_int($generation)) {
            $exists = false !== $connection->fetchOne('SELECT 1 FROM cpg_user WHERE id = :id', ['id' => $user->getId()], ['id' => UuidType::NAME]);

            throw $exists ? AccountNotAwaitingActivationException::alreadyActivated() : CpgUserNotFoundException::forId($user->getId());
        }

        $unitOfWork = $this->getEntityManager()->getUnitOfWork();
        if ($unitOfWork->isInIdentityMap($user)) {
            $unitOfWork->setOriginalEntityProperty(spl_object_id($user), 'invitationGeneration', $generation);
            $unitOfWork->setOriginalEntityProperty(spl_object_id($user), 'invitedAt', $invitedAt);
        }

        return $generation;
    }

    public function remove(CpgUser $user): void
    {
        $this->getEntityManager()->remove($user);
        $this->getEntityManager()->flush();
    }

    /**
     * En DQL par identifiant, comme PasswordSetupTokenRepository::remove()
     * (#425) : un `flush()` global écrirait au passage d'autres changements en
     * attente, et un `clear()` survenu entre-temps ferait lever
     * `EntityManager::remove()`. Jamais un compte activé : l'e-mail a pu partir
     * malgré l'erreur (accusé du broker perdu, #479) et la personne définir son
     * mot de passe. Un jeton émis entre-temps par le handler part avec le
     * compte (`ON DELETE CASCADE`).
     *
     * Le compte dont la ligne a été supprimée ici est détaché et retenu par
     * {@see VanishedEntities::deleteById()} (issues #569, #560) : resté géré, une
     * modification puis un `save()` émettaient un `UPDATE` sur 0 ligne, perdu
     * en silence ; sa re-sauvegarde est désormais refusée. Un compte épargné
     * (activé entre-temps) reste géré : sa ligne existe. Un jeton qu'un
     * `ON DELETE CASCADE` emporte n'est pas détaché : aucun n'est chargé ici.
     */
    public function removePendingInvitation(CpgUser $user): void
    {
        $this->vanishedEntities->deleteById(
            $this->createQueryBuilder('user')
                ->delete()
                ->where('user.id = :id')
                ->andWhere('user.activatedAt IS NULL')
                ->setParameter('id', $user->getId(), UuidType::NAME)
                ->getQuery(),
            $user,
        );
    }

    /**
     * Ré-encode et persiste le mot de passe si l'algorithme de hash configuré a changé.
     *
     * **Comparer pour échanger** (F3 de la relecture de #650) : `UPDATE … SET
     * password = :nouveau WHERE id = :id AND password = :ancien`, une seule
     * instruction, qui n'écrit que si le hachage en base est encore celui que la
     * connexion a lu (`$user`, relu à l'authentification). Le `save()` d'avant
     * écrivait le compte tel qu'il avait été lu, hors de tout verrou : une
     * connexion par l'ancien mot de passe, dont le hachage devait être
     * renouvelé, pouvait après un changement de mot de passe validé entre-temps
     * (le `PUT` du backoffice, un lien d'invitation) sauver `rehash(ancien)` et
     * rétablir l'ancien mot de passe — celui que le changement voulait retirer.
     *
     * Rejeté : passer par `AccountLockInterface` (une transaction, un `FOR
     * UPDATE`, une relecture pour une écriture rare, et une dépendance de
     * l'Infrastructure vers l'Application). Le coût de l'instruction
     * conditionnelle : un re-hachage perdu quand le mot de passe a changé, sans
     * conséquence — la prochaine connexion rehache le nouveau. Aucune ligne
     * touchée (hachage changé, compte supprimé) n'est pas une erreur.
     *
     * L'instance reçoit le nouveau hachage et la carte d'identité le prend pour
     * état d'origine, comme `advanceInvitationGeneration()` : sans quoi un
     * `flush()` ultérieur la croirait sale et réécrirait son hachage par-dessus
     * un autre changement. Le re-hachage garde le même secret : il ne date
     * aucun changement de mot de passe (`credentialsVersion` n'avance pas).
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof CpgUser) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $written = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE cpg_user SET password = :new WHERE id = :id AND password = :old',
            ['new' => $newHashedPassword, 'id' => $user->getId(), 'old' => $user->getPassword()],
            ['id' => UuidType::NAME],
        );

        if (0 === $written) {
            return;
        }

        $user->setPassword($newHashedPassword);
        $unitOfWork = $this->getEntityManager()->getUnitOfWork();
        if ($unitOfWork->isInIdentityMap($user)) {
            $unitOfWork->setOriginalEntityProperty(spl_object_id($user), 'password', $newHashedPassword);
        }
    }
}
