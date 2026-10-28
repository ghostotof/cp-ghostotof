<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Domain\Entity;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\InvalidUsernameException;
use App\Tests\Support\TestCredentials;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

final class CpgUserTest extends TestCase
{
    /**
     * Spec 0003 D1 : l'identite est posee par le constructeur, pas par le
     * flush. Une entite construite est donc deja identifiable, comparable et
     * testable sans base de donnees.
     */
    public function testANewUserIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        self::assertInstanceOf(UuidV7::class, $user->getId());
    }

    /**
     * Pin le v7 et non le v4 : deux constructions successives doivent donner
     * des identifiants distincts et croissants, sur quoi repose l'ordre de
     * repli `ORDER BY id` des repositories.
     */
    public function testTwoUsersBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = new CpgUser('jane', 'hashed-password');
        $second = new CpgUser('john', 'hashed-password');

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    public function testUserIdentifierIsUsername(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        self::assertSame('jane', $user->getUserIdentifier());
        self::assertSame('jane', $user->getUsername());
    }

    /**
     * Issue #386 : le `$` de PCRE accepte la position qui précède un `\n`
     * final. Un nom suivi d'un retour à la ligne passait, et donnait un compte
     * distinct qui s'affiche comme son homonyme dans le backoffice et dans
     * `security_audit`.
     */
    public function testAUsernameEndingWithALineFeedIsRefused(): void
    {
        $this->expectException(InvalidUsernameException::class);

        new CpgUser("jane\n", 'hashed-password');
    }

    /**
     * Revue de #386 : comme l'invite de la CLI (issue #383), le constructeur
     * ne cite pas la saisie refusée — elle peut être n'importe quoi, un mot de
     * passe collé au mauvais endroit compris, et le message d'une exception
     * finit dans les journaux.
     */
    public function testARefusedUsernameIsNotQuotedInTheException(): void
    {
        // Le « ! » le rend invalide comme nom : la valeur générée seule passerait le motif.
        $pasted = TestCredentials::variant('pasted').'!';

        try {
            new CpgUser($pasted, 'hashed-password');
            self::fail('Le nom d\'utilisateur aurait dû être refusé.');
        } catch (InvalidUsernameException $exception) {
            self::assertStringNotContainsString($pasted, $exception->getMessage());
        }
    }

    public function testRolesAlwaysIncludeRoleUser(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testRoleUserIsNotDuplicatedWhenAlreadyPresent(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    /**
     * Le rôle d'administration se lit sur les rôles **stockés** (issue #560) :
     * ROLE_TRUSTED, que ROLE_SUPER hérite par la role_hierarchy, ne le donne
     * pas en retour.
     */
    public function testOnlyAnAccountHoldingRoleSuperIsASuperAdmin(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        self::assertFalse($user->isSuperAdmin(), 'Un compte sans rôle est dit super-administrateur.');

        $user->setRoles([CpgUser::ROLE_TRUSTED]);
        self::assertFalse($user->isSuperAdmin(), 'ROLE_TRUSTED a été pris pour ROLE_SUPER.');

        $user->setRoles([CpgUser::ROLE_SUPER]);
        self::assertTrue($user->isSuperAdmin());

        $user->setRoles([CpgUser::ROLE_TRUSTED]);
        self::assertFalse($user->isSuperAdmin(), 'Une rétrogradation est restée super-administratrice.');
    }

    public function testPasswordCanBeUpgraded(): void
    {
        $user = new CpgUser('jane', 'old-hash');
        $user->setPassword('new-hash');

        self::assertSame('new-hash', $user->getPassword());
    }

    public function testEraseCredentialsDoesNotThrow(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        $user->eraseCredentials();

        self::assertSame('hashed-password', $user->getPassword());
    }

    public function testANewUserHasNoEmailNorInvitationAndIsNotPendingActivation(): void
    {
        // Un compte fraîchement construit (voie CLI) est utilisable d'emblée :
        // il n'a pas de cycle invitation/activation, donc n'est pas "en attente".
        $user = new CpgUser('jane', 'hashed-password');

        self::assertNull($user->getEmail());
        self::assertNull($user->getInvitedAt());
        self::assertFalse($user->isPendingActivation());
    }

    public function testEmailCanBeSet(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->setEmail('jane@example.com');

        self::assertSame('jane@example.com', $user->getEmail());
    }

    public function testMarkInvitedRecordsTheTimestampWithoutActivating(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $invitedAt = new DateTimeImmutable('2026-09-03 12:00:00');

        $user->markInvited($invitedAt);

        self::assertSame($invitedAt, $user->getInvitedAt());
        self::assertTrue($user->isPendingActivation());
    }

    /**
     * Issue #434 : chaque (re)invitation a son numéro, que porte son message.
     * Un message dont le numéro est dépassé est périmé, quel que soit
     * l'instant affiché par l'horloge.
     */
    public function testEachInvitationAdvancesTheInvitationGeneration(): void
    {
        $user = new CpgUser('jane', '');
        self::assertSame(0, $user->getInvitationGeneration());

        $user->markInvited(new DateTimeImmutable('2026-09-03 12:00:00'));
        self::assertSame(1, $user->getInvitationGeneration());

        // Une relance horodatée plus tôt (horloge reculée) avance quand même.
        $user->markInvited(new DateTimeImmutable('2026-09-03 11:00:00'));
        self::assertSame(2, $user->getInvitationGeneration());
    }

    /**
     * Issue #434 : un message d'invitation est périmé quand le compte a été
     * relancé depuis la génération qu'il sert.
     */
    public function testAnAccountWasReinvitedSinceAnyEarlierGenerationOnly(): void
    {
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-03 12:00:00'));
        $user->markInvited(new DateTimeImmutable('2026-09-04 12:00:00'));

        self::assertTrue($user->wasReinvitedSince(1));
        self::assertFalse($user->wasReinvitedSince(2));
        // Un message d'avance sur la base (relance pas encore commitée) n'est
        // jamais écarté : le dernier message ne doit pas se perdre.
        self::assertFalse($user->wasReinvitedSince(3));
    }

    public function testMarkActivatedClearsThePendingState(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->markInvited(new DateTimeImmutable('2026-09-03 12:00:00'));

        $user->markActivated(new DateTimeImmutable('2026-09-04 09:30:00'));

        self::assertFalse($user->isPendingActivation());
    }

    /**
     * Round de correction (I2, issue #238) : un compte fraîchement invité, au
     * mot de passe encore vide, est bien « en attente de définition de mot de
     * passe » — la définition littérale que retient la purge automatique.
     */
    public function testANewlyInvitedAccountIsAwaitingPasswordSetup(): void
    {
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-03 12:00:00'));

        self::assertTrue($user->isAwaitingPasswordSetup());
    }

    /**
     * I2 : CpgUserAdministrator::changePassword() peut poser un mot de passe
     * sur un compte sur invitation sans jamais appeler markActivated() — un tel compte
     * reste isPendingActivation() bien qu'il se connecte déjà. Il ne doit
     * jamais être purgeable : isAwaitingPasswordSetup() doit dire faux, alors
     * même qu'isPendingActivation() dit encore vrai.
     */
    public function testAnAccountWhosePasswordWasSetFromTheBackofficeIsNotAwaitingPasswordSetup(): void
    {
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-03 12:00:00'));

        $user->setPassword('a-real-hash-set-from-the-backoffice');

        self::assertTrue($user->isPendingActivation());
        self::assertFalse($user->isAwaitingPasswordSetup());
    }

    /**
     * « Mot de passe défini » a un seul sens (issue #560) : un hachage autre
     * que NO_PASSWORD. Un compte sur invitation naît sans mot de passe, en reçoit un
     * par le lien ou par le backoffice ; un compte CLI en reçoit un aussitôt.
     */
    public function testAnAccountHasAPasswordOnceItsHashIsNoLongerNoPassword(): void
    {
        $user = new CpgUser('jane', CpgUser::NO_PASSWORD);
        self::assertFalse($user->hasPassword(), 'Un compte sans mot de passe est dit en avoir un.');

        $user->setPassword('a-real-hash-set-from-the-backoffice');
        self::assertTrue($user->hasPassword());
    }

    /**
     * Un compte CLI n'a jamais été invité : ni en attente d'activation, ni en
     * attente de mot de passe.
     */
    public function testACliCreatedAccountIsNeverAwaitingPasswordSetup(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        self::assertFalse($user->isAwaitingPasswordSetup());
    }

    /**
     * Un compte déjà activé (mot de passe défini via le lien) n'est plus en
     * attente, même si — par construction — son mot de passe ne peut alors
     * plus être vide.
     */
    public function testAnActivatedAccountIsNotAwaitingPasswordSetup(): void
    {
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-03 12:00:00'));
        $user->setPassword('a-real-hash');
        $user->markActivated(new DateTimeImmutable('2026-09-04 09:30:00'));

        self::assertFalse($user->isAwaitingPasswordSetup());
    }

    /** Un compte que personne n'a jamais changé de mot de passe n'a aucun instant : aucun cookie n'est jugé périmé. */
    public function testANewAccountHasNoCredentialsChangeAndStalesNoSession(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        self::assertNull($user->getCredentialsChangedAt());
        self::assertFalse($user->hasChangedCredentialsSince(new DateTimeImmutable('2026-10-28 10:00:00')));
        self::assertFalse($user->hasChangedCredentialsSince(null), 'Sans changement de mot de passe, un instant d\'authentification inconnu ne périme rien.');
    }

    /** `replacePassword()` change le mot de passe ET date le changement, ensemble (F2, #650). */
    public function testReplacingThePasswordRecordsTheInstant(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $at = new DateTimeImmutable('2026-10-28 10:00:00');

        $user->replacePassword('another-hash', $at);

        self::assertSame('another-hash', $user->getPassword());
        self::assertEquals($at, $user->getCredentialsChangedAt());
    }

    /**
     * Le simple re-hachage (`PasswordUpgraderInterface`, même secret) passe par
     * `setPassword()` et ne date rien : il ne doit pas périmer les sessions.
     */
    public function testSettingThePasswordAloneRecordsNoInstant(): void
    {
        $user = new CpgUser('jane', 'hashed-password');

        $user->setPassword('rehashed-same-secret');

        self::assertNull($user->getCredentialsChangedAt());
    }

    /**
     * Le choix large : une authentification **antérieure ou égale** au
     * changement est périmée. L'instant d'authentification (`iat` du JWT) est
     * à la seconde et la colonne `TIMESTAMP(0)` arrondit : une connexion de la
     * même seconde que le changement ne se distingue pas d'une connexion
     * d'avant, et la prendre pour d'après laisserait passer un cookie volé.
     * Le coût — une personne qui se reconnecte dans la seconde — est nul.
     */
    public function testAnAuthenticationAtOrBeforeTheChangeIsStale(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->replacePassword('another-hash', new DateTimeImmutable('2026-10-28 10:00:00'));

        self::assertTrue($user->hasChangedCredentialsSince(new DateTimeImmutable('2026-10-28 09:59:59')));
        self::assertTrue($user->hasChangedCredentialsSince(new DateTimeImmutable('2026-10-28 10:00:00')), 'La même seconde est périmée.');
        self::assertFalse($user->hasChangedCredentialsSince(new DateTimeImmutable('2026-10-28 10:00:01')));
    }

    /** Un instant d'authentification qu'on ne peut établir est tenu pour antérieur, comme le fait déjà le consentement. */
    public function testAnUnknownAuthenticationInstantIsStaleOnceCredentialsChanged(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->replacePassword('another-hash', new DateTimeImmutable('2026-10-28 10:00:00'));

        self::assertTrue($user->hasChangedCredentialsSince(null));
    }

    /** Les instants se comparent comme des instants : un fuseau différent ne décale pas le verdict. */
    public function testTheComparisonIgnoresTheZoneOfTheInstants(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $user->replacePassword('another-hash', new DateTimeImmutable('2026-10-28 10:00:00+02:00'));

        self::assertTrue($user->hasChangedCredentialsSince(new DateTimeImmutable('2026-10-28 08:00:00+00:00')));
        self::assertFalse($user->hasChangedCredentialsSince(new DateTimeImmutable('2026-10-28 08:00:01+00:00')));
    }
}
