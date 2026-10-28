<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Infrastructure\Doctrine\CpgUserRepository;
use App\Shared\Infrastructure\Doctrine\VanishedEntitySavedAgainException;
use App\Tests\Support\ReadsAllChannelsLog;
use App\Tests\Support\TestCredentials;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Test d'intégration Doctrine (base `_test`) de
 * `findAwaitingPasswordSetupInvitedBefore()`, la méthode dont se sert
 * PendingInvitationPurger (issue #238) pour retrouver les comptes en attente
 * de définition de mot de passe invités depuis trop longtemps. Round de
 * correction (I2) : renommée depuis `findPendingActivationInvitedBefore()` et
 * son prédicat SQL complété d'un mot de passe vide — un compte sur invitation dont le
 * mot de passe a été posé depuis le backoffice
 * (CpgUserAdministrator::changePassword(), sans jamais markActivated()) reste
 * `isPendingActivation()` vrai mais ne doit plus être purgeable.
 */
final class CpgUserRepositoryTest extends KernelTestCase
{
    use ReadsAllChannelsLog;

    private EntityManagerInterface $em;
    private CpgUserRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(CpgUserRepositoryInterface::class);

        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }

    /**
     * Issue #434 : la colonne garde l'instant, pas l'heure murale. Le 25
     * octobre, 02:30 existe deux fois à Paris ; sans fuseau en base, la
     * première occurrence (CEST) revenait comme la seconde (CET), une heure
     * plus tard.
     */
    public function testTheInvitationInstantSurvivesTheRepeatedHourOfTheAutumnClockChange(): void
    {
        $invitedAt = new DateTimeImmutable('2026-10-25T02:30:00+02:00');
        $activatedAt = new DateTimeImmutable('2026-10-25T02:45:00+02:00');
        $user = new CpgUser('autumn-invitee', '');
        $user->setEmail('autumn@example.com');
        $user->markInvited($invitedAt);
        $user->markActivated($activatedAt);
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->repository->findOneById($user->getId());

        self::assertNotNull($reloaded);
        self::assertSame($invitedAt->getTimestamp(), $reloaded->getInvitedAt()?->getTimestamp());
        self::assertSame($activatedAt->getTimestamp(), $reloaded->getActivatedAt()?->getTimestamp());
    }

    /**
     * Le seuil de la purge est comparé en instant, quel que soit le fuseau
     * de l'objet qui le porte — ici UTC, celui d'une valeur relue. Écrit en
     * heure murale sans décalage, 07:30 UTC serait lu 07:30 dans le fuseau de
     * la session (Paris), soit 05:30 UTC, et le compte sur invitation à 07:00 UTC ne
     * passerait plus pour plus ancien que le seuil.
     */
    public function testThePurgeThresholdIsComparedAsAnInstant(): void
    {
        $user = new CpgUser('threshold-invitee', '');
        $user->setEmail('threshold@example.com');
        $user->markInvited(new DateTimeImmutable('2026-10-11T07:00:00+00:00'));
        $this->em->persist($user);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->repository->findAwaitingPasswordSetupInvitedBefore(new DateTimeImmutable('2026-10-11T06:30:00+00:00')));
        self::assertCount(1, $this->repository->findAwaitingPasswordSetupInvitedBefore(new DateTimeImmutable('2026-10-11T07:30:00+00:00')));
    }

    /**
     * Trois comptes : invité il y a 40 jours (encore en attente), invité il y
     * a 10 jours (encore en attente, trop récent) et invité il y a 40 jours
     * puis activé (donc plus en attente). Avec un seuil à -30 jours, seul le
     * premier doit ressortir.
     */
    public function testFindAwaitingPasswordSetupInvitedBeforeOnlyReturnsStalePendingAccounts(): void
    {
        $stale = new CpgUser('stale-invitee', '');
        $stale->setEmail('stale@example.com');
        $stale->markInvited(new DateTimeImmutable('-40 days'));
        $this->em->persist($stale);

        $recent = new CpgUser('recent-invitee', '');
        $recent->setEmail('recent@example.com');
        $recent->markInvited(new DateTimeImmutable('-10 days'));
        $this->em->persist($recent);

        $activated = new CpgUser('activated-invitee', '');
        $activated->setEmail('activated@example.com');
        $activated->markInvited(new DateTimeImmutable('-40 days'));
        $activated->markActivated(new DateTimeImmutable('-5 days'));
        $this->em->persist($activated);

        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findAwaitingPasswordSetupInvitedBefore(new DateTimeImmutable('-30 days'));

        self::assertCount(1, $found);
        self::assertSame('stale-invitee', $found[0]->getUsername());
    }

    public function testFindAwaitingPasswordSetupInvitedBeforeReturnsEmptyListWhenNothingIsStale(): void
    {
        $recent = new CpgUser('recent-invitee', '');
        $recent->setEmail('recent@example.com');
        $recent->markInvited(new DateTimeImmutable('-10 days'));
        $this->em->persist($recent);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->repository->findAwaitingPasswordSetupInvitedBefore(new DateTimeImmutable('-30 days')));
    }

    /**
     * I2 : un compte sur invitation peut se voir poser un mot de passe depuis le
     * backoffice (CpgUserAdministrator::changePassword()) sans jamais être
     * marqué activé — il se connecte déjà et ne doit plus jamais être purgé,
     * même très ancien.
     */
    public function testFindAwaitingPasswordSetupInvitedBeforeExcludesAnAccountWhosePasswordWasSetFromTheBackoffice(): void
    {
        $passwordSet = new CpgUser('password-set-invitee', 'a-real-hash-set-from-the-backoffice');
        $passwordSet->setEmail('password-set@example.com');
        $passwordSet->markInvited(new DateTimeImmutable('-40 days'));
        $this->em->persist($passwordSet);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->repository->findAwaitingPasswordSetupInvitedBefore(new DateTimeImmutable('-30 days')));
    }

    /**
     * I4 (défense en profondeur, symétrique au test unitaire du purgeur) : un
     * compte créé en ligne de commande (app:user:create) n'a jamais été
     * invité — invitedAt reste null pour toujours, quel que soit l'âge du
     * compte — et ne doit donc jamais ressortir, y compris avec un seuil très
     * large (dans le futur).
     */
    public function testFindAwaitingPasswordSetupInvitedBeforeNeverReturnsAnAccountCreatedWithoutAnyInvitation(): void
    {
        $cliAccount = new CpgUser('cli-created-account', 'hashed-password');
        $this->em->persist($cliAccount);
        $this->em->flush();
        $this->em->clear();

        self::assertSame([], $this->repository->findAwaitingPasswordSetupInvitedBefore(new DateTimeImmutable('+1 day')));
    }

    /**
     * L'ordre (`ORDER BY invitedAt ASC`) importe : le purgeur journalise et
     * agit dans un ordre stable plutôt que dans l'ordre arbitraire du moteur.
     */
    public function testFindAwaitingPasswordSetupInvitedBeforeOrdersFromOldestToMostRecent(): void
    {
        $newer = new CpgUser('older-of-the-stale-two', '');
        $newer->setEmail('newer-stale@example.com');
        $newer->markInvited(new DateTimeImmutable('-35 days'));
        $this->em->persist($newer);

        $older = new CpgUser('newer-of-the-stale-two', '');
        $older->setEmail('older-stale@example.com');
        $older->markInvited(new DateTimeImmutable('-50 days'));
        $this->em->persist($older);

        $this->em->flush();
        $this->em->clear();

        $found = $this->repository->findAwaitingPasswordSetupInvitedBefore(new DateTimeImmutable('-30 days'));

        self::assertCount(2, $found);
        self::assertSame('newer-of-the-stale-two', $found[0]->getUsername());
        self::assertSame('older-of-the-stale-two', $found[1]->getUsername());
    }

    /**
     * Issue #418 : le chargeur du fournisseur `cpg_users` (security.yaml).
     * Un compte existant est rendu tel quel.
     */
    /**
     * Issue #485 : l'invitation que la file a refusée est défaite par un
     * DELETE ciblé, sans flush() global — l'appelant est un chemin d'échec,
     * comme PasswordSetupTokenRepository::remove() (#425).
     */
    public function testRemovePendingInvitationDeletesTheAccountByItsIdentifier(): void
    {
        $user = new CpgUser('undispatched', '');
        $user->setEmail('undispatched@example.com');
        $user->markInvited(new DateTimeImmutable('2026-10-11T18:00:00+02:00'));
        $this->repository->save($user);
        $this->em->clear();

        $this->repository->removePendingInvitation($user);

        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cpg_user'));
    }

    /**
     * Jamais un compte activé entre-temps : l'e-mail a pu partir malgré
     * l'erreur (accusé du broker perdu, #479) et la personne définir son mot
     * de passe ; le supprimer effacerait un compte vivant.
     */
    public function testRemovePendingInvitationSparesAnActivatedAccount(): void
    {
        $user = new CpgUser('activated-meanwhile', 'hashed-password');
        $user->setEmail('activated@example.com');
        $user->markInvited(new DateTimeImmutable('2026-10-11T18:00:00+02:00'));
        $user->markActivated(new DateTimeImmutable('2026-10-11T18:01:00+02:00'));
        $this->repository->save($user);

        $this->repository->removePendingInvitation($user);

        self::assertSame(1, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cpg_user'));
    }

    /**
     * Issue #569 : la suppression DQL laissait l'instance **gérée** alors que
     * sa ligne n'existait plus. Une modification puis un `save()` émettaient
     * un `UPDATE` sur 0 ligne — Doctrine ne lit pas ce compte pour une entité
     * non versionnée —, l'écriture perdue en silence. L'instance est
     * désormais détachée et retenue par VanishedEntities : sa re-sauvegarde
     * est refusée sous la cause « supprimée par cet EntityManager ».
     */
    public function testRemovePendingInvitationForgetsTheDeletedAccountAndRefusesItsResave(): void
    {
        $user = new CpgUser('undispatched', '');
        $user->setEmail('undispatched@example.com');
        $user->markInvited(new DateTimeImmutable('2026-10-11T18:00:00+02:00'));
        $this->repository->save($user);
        $id = $user->getId()->toRfc4122();

        $this->repository->removePendingInvitation($user);
        $stillManaged = $this->em->contains($user);
        $user->markInvited(new DateTimeImmutable('2026-10-11T18:05:00+02:00'));
        $refusal = null;
        try {
            $this->repository->save($user);
        } catch (VanishedEntitySavedAgainException $exception) {
            $refusal = $exception;
        }

        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cpg_user'), 'Le compte supprimé, re-sauvé, a été réinséré.');
        self::assertFalse($stillManaged, "Le compte supprimé en DQL est resté géré par l'EntityManager (#569).");
        self::assertNotNull($refusal, 'La re-sauvegarde du compte supprimé en DQL est passée en silence, UPDATE sur 0 ligne (#569).');
        self::assertSame(VanishedEntitySavedAgainException::removedByThisManager(CpgUser::class, $id)->getMessage(), $refusal->getMessage());
    }

    /**
     * Le compte activé entre-temps n'est pas supprimé (0 ligne touchée) : il
     * reste géré, et l'écrire reste possible. Seul un DELETE qui a touché sa
     * ligne détache l'instance.
     */
    public function testRemovePendingInvitationLeavesASparedAccountManaged(): void
    {
        $user = new CpgUser('activated-meanwhile', 'hashed-password');
        $user->setEmail('activated@example.com');
        $user->markInvited(new DateTimeImmutable('2026-10-11T18:00:00+02:00'));
        $user->markActivated(new DateTimeImmutable('2026-10-11T18:01:00+02:00'));
        $this->repository->save($user);

        $this->repository->removePendingInvitation($user);
        $stillManaged = $this->em->contains($user);
        $user->setEmail('activated.renamed@example.com');
        $this->repository->save($user);

        self::assertTrue($stillManaged, 'Le compte épargné par la suppression a été détaché.');
        self::assertSame('activated.renamed@example.com', $this->em->getConnection()->fetchOne('SELECT email FROM cpg_user'));
    }

    public function testLoadUserByIdentifierReturnsTheAccount(): void
    {
        $this->em->persist(new CpgUser('jane', 'hashed-password'));
        $this->em->flush();
        $this->em->clear();

        self::assertSame('jane', self::getContainer()->get(CpgUserRepository::class)->loadUserByIdentifier('jane')->getUsername());
    }

    /**
     * Issue #418 : un identifiant inconnu — souvent un mot de passe tapé dans
     * le mauvais champ — n'est pas recopié dans le message de l'exception.
     * Le canal `security` de Symfony journalise ce message en `info`, donc en
     * production ; celui du fournisseur `entity` de Doctrine était
     * `User "<identifiant>" not found.`. L'identifiant reste porté par
     * l'exception (`getUserIdentifier()`), que le formateur JSON n'écrit pas.
     */
    public function testAnUnknownIdentifierIsRefusedWithoutQuotingItInTheMessage(): void
    {
        try {
            self::getContainer()->get(CpgUserRepository::class)->loadUserByIdentifier(TestCredentials::variant('typed-as-identifier'));
            self::fail('Un identifiant inconnu doit lever UserNotFoundException.');
        } catch (UserNotFoundException $exception) {
            self::assertStringNotContainsStringIgnoringCase(TestCredentials::variant('typed-as-identifier'), $exception->getMessage());
            self::assertSame(TestCredentials::variant('typed-as-identifier'), $exception->getUserIdentifier());
        }
    }

    /**
     * Relecture de #418 : un identifiant qui ne respecte pas
     * CpgUser::USERNAME_PATTERN ne peut désigner aucun compte (le
     * constructeur l'impose) ; il n'est donc pas cherché. Sans ce refus, un
     * octet NUL coupait le paramètre côté PostgreSQL et `jane\0…` chargeait
     * `jane` ; un octet hors UTF-8 faisait échouer la requête (500).
     */
    #[DataProvider('identifiersNoAccountCanHave')]
    public function testAnIdentifierNoAccountCanHaveIsRefusedWithoutQuery(string $identifier): void
    {
        $this->em->persist(new CpgUser('jane', 'hashed-password'));
        $this->em->flush();
        $this->em->clear();

        // Témoin positif : un identifiant valide est bien cherché, donc compté
        // — sans lui, une journalisation SQL absente rendrait l'assertion
        // finale vraie à vide.
        $queriesBefore = $this->cpgUserQueries();
        self::getContainer()->get(CpgUserRepository::class)->loadUserByIdentifier('jane');
        self::assertSame($queriesBefore + 1, $this->cpgUserQueries(), 'La requête d\'un identifiant valide doit être vue.');
        $queriesBefore = $this->cpgUserQueries();

        try {
            self::getContainer()->get(CpgUserRepository::class)->loadUserByIdentifier($identifier);
            self::fail('Un identifiant hors motif doit lever UserNotFoundException.');
        } catch (UserNotFoundException) {
            self::assertSame($queriesBefore, $this->cpgUserQueries(), 'Refusé sans requête.');
        }
    }

    /**
     * Requêtes sur `cpg_user` journalisées jusqu'ici (canal `doctrine`,
     * `debug`, sous `kernel.debug` comme en test).
     */
    private function cpgUserQueries(): int
    {
        return \count(array_filter(
            self::allChannelsLogRecords(),
            static fn (LogRecord $record): bool => 'doctrine' === $record->channel && str_contains($record->message, 'FROM cpg_user'),
        ));
    }

    /**
     * Issue #551 : la garde du dernier ROLE_SUPER compte les **autres**
     * comptes ROLE_SUPER capables de se connecter — la cible exclue, un compte
     * invité jamais activé (mot de passe vide) exclu, un compte sans le rôle
     * exclu.
     */
    public function testCountOtherSuperAdminsAbleToSignInExcludesTheTargetThePendingOnesAndThePlainOnes(): void
    {
        $target = $this->storedSuperAdmin('target', 'hashed-password');
        $this->storedSuperAdmin('able', 'hashed-password');
        $this->storedSuperAdmin('pending', '');
        $this->storedUser('plain', 'hashed-password', []);

        self::assertSame(1, $this->repository->countOtherSuperAdminsAbleToSignIn($target));
    }

    /**
     * Compté en base, jamais sur la carte d'identité : le seul autre ROLE_SUPER
     * est encore ROLE_SUPER en mémoire, plus en base.
     */
    public function testCountOtherSuperAdminsAbleToSignInReadsTheDatabaseNotTheIdentityMap(): void
    {
        $target = $this->storedSuperAdmin('target', 'hashed-password');
        $demotedMeanwhile = $this->storedSuperAdmin('demoted', 'hashed-password');
        $this->em->getConnection()->executeStatement("UPDATE cpg_user SET roles = '[]' WHERE id = :id", ['id' => $demotedMeanwhile->getId()->toRfc4122()]);

        self::assertContains(CpgUser::ROLE_SUPER, $demotedMeanwhile->getRoles(), 'La carte d\'identité doit encore tenir le rôle, c\'est elle que le test accuse.');
        self::assertSame(0, $this->repository->countOtherSuperAdminsAbleToSignIn($target));
    }

    /**
     * F3 de la relecture de #650 : `upgradePassword()` (le re-hachage à la
     * connexion, `PasswordUpgraderInterface`) écrivait hors de tout verrou, le
     * compte tel que la connexion l'avait lu. Une connexion par l'ancien mot de
     * passe dont le hachage doit être renouvelé pouvait donc, après qu'un `PUT` du
     * backoffice a validé un nouveau mot de passe, sauver `rehash(ancien)` et
     * **rétablir l'ancien mot de passe**. L'écriture ne se fait plus que si le
     * hachage en base est encore celui que la connexion a lu.
     *
     * La course : le compte est lu (ancien hachage), un changement de mot de
     * passe est validé entre-temps, puis le re-hachage de l'ancien arrive.
     */
    public function testAStaleRehashNeverRestoresAReplacedPassword(): void
    {
        $user = $this->storedUser('rehash-race', 'old-hash', []);
        // Le changement de mot de passe d'un autre processus, validé après la lecture du compte.
        $this->em->getConnection()->executeStatement('UPDATE cpg_user SET password = :new WHERE id = :id', ['new' => 'new-hash', 'id' => $user->getId()], ['id' => UuidType::NAME]);

        $this->upgrader()->upgradePassword($user, 'rehash-of-the-old-one');

        self::assertSame('new-hash', $this->storedPasswordOf($user), 'Le re-hachage de l\'ancien mot de passe a rétabli celui-ci.');
    }

    /**
     * Le cas nominal : le hachage en base est celui que la connexion a lu, le
     * nouveau le remplace. L'instance en main le porte, n'avance pas
     * la version des identifiants (même secret : aucune session n'est
     * périmée, #650 S1) et n'est pas « sale » — un
     * `flush()` ultérieur ne réécrit pas ce hachage par-dessus un autre.
     */
    public function testARehashOfTheCurrentHashIsWrittenWithoutAdvancingTheVersion(): void
    {
        $user = $this->storedUser('rehash-nominal', 'old-hash', []);

        $this->upgrader()->upgradePassword($user, 'rehashed');

        self::assertSame('rehashed', $this->storedPasswordOf($user));
        self::assertSame('rehashed', $user->getPassword());
        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT credentials_version FROM cpg_user WHERE id = :id', ['id' => $user->getId()], ['id' => UuidType::NAME]), 'Un re-hachage a avancé la version des identifiants : il périmerait les sessions.');
        self::assertSame(0, $user->getCredentialsVersion());

        // Un autre écrivain passe ensuite ; le flush de l'instance, qu'on croirait sale, n'y touche pas.
        $this->em->getConnection()->executeStatement('UPDATE cpg_user SET password = :new WHERE id = :id', ['new' => 'changed-later', 'id' => $user->getId()], ['id' => UuidType::NAME]);
        $this->em->flush();

        self::assertSame('changed-later', $this->storedPasswordOf($user), 'L\'instance était restée sale et a réécrit son hachage.');
    }

    /** Le compte supprimé entre la connexion et le re-hachage : rien à écrire, rien ne lève. */
    public function testARehashOfADeletedAccountWritesNothing(): void
    {
        $user = $this->storedUser('rehash-gone', 'old-hash', []);
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user WHERE id = :id', ['id' => $user->getId()], ['id' => UuidType::NAME]);

        $this->upgrader()->upgradePassword($user, 'rehashed');

        self::assertSame(0, $this->em->getConnection()->fetchOne('SELECT count(*) FROM cpg_user'));
    }

    private function upgrader(): CpgUserRepository
    {
        $repository = $this->repository;
        self::assertInstanceOf(CpgUserRepository::class, $repository);

        return $repository;
    }

    private function storedPasswordOf(CpgUser $user): mixed
    {
        return $this->em->getConnection()->fetchOne('SELECT password FROM cpg_user WHERE id = :id', ['id' => $user->getId()], ['id' => UuidType::NAME]);
    }

    private function storedSuperAdmin(string $username, string $hashedPassword): CpgUser
    {
        return $this->storedUser($username, $hashedPassword, [CpgUser::ROLE_SUPER]);
    }

    /**
     * @param list<string> $roles
     */
    private function storedUser(string $username, string $hashedPassword, array $roles): CpgUser
    {
        $user = new CpgUser($username, $hashedPassword);
        $user->setRoles($roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** @return iterable<string, array{string}> */
    public static function identifiersNoAccountCanHave(): iterable
    {
        yield 'coupé par un octet NUL' => ["jane\0suffix"];
        yield 'octet hors UTF-8' => ["jane\xFF"];
        yield 'fin de ligne' => ["jane\n"];
    }
}
