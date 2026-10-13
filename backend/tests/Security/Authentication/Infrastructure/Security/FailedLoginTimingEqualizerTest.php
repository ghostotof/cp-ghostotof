<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\FailedLoginTimingEqualizer;
use App\Security\Authentication\Infrastructure\Security\SubmittedPasswordTooLongException;
use App\Security\User\Domain\Entity\CpgUser;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Constat A10 : les trois échecs de `POST /api/login_check` répondent le même
 * 401 mais pas dans le même temps. Ce qui est testé ici est exactement le
 * critère de déclenchement — *la vraie vérification a-t-elle eu lieu ?* — et
 * non une durée : un test de chronométrage serait instable par construction,
 * et le coût du hasher est abaissé en environnement de test. La preuve est
 * donc l'appel (ou non) au hasher.
 */
final class FailedLoginTimingEqualizerTest extends TestCase
{
    private const string FIREWALL = 'login';

    private (PasswordHasherInterface&MockObject)|null $hasher = null;

    /** Ce pour quoi la fabrique a été interrogée, ou `null` si elle ne l'a pas été. */
    private ?string $hasherRequestedFor = null;

    private function hasher(): PasswordHasherInterface&MockObject
    {
        return $this->hasher ??= $this->createMock(PasswordHasherInterface::class);
    }

    private function equalizer(): FailedLoginTimingEqualizer
    {
        $factory = self::createStub(PasswordHasherFactoryInterface::class);
        $factory->method('getPasswordHasher')->willReturnCallback(
            function (string|PasswordAuthenticatedUserInterface $user): PasswordHasherInterface {
                $this->hasherRequestedFor = \is_string($user) ? $user : $user::class;

                return $this->hasher();
            },
        );

        return new FailedLoginTimingEqualizer($factory);
    }

    /**
     * La fabrique est interrogée pour `CpgUser::class` : le hachage factice
     * suit donc l'algorithme et le coût réellement configurés pour les
     * comptes, y compris si `security.yaml` change demain — et le coût abaissé
     * en environnement de test s'y applique comme à la vraie vérification.
     */
    public function testTheDummyHashUsesTheHasherConfiguredForCpgUser(): void
    {
        $this->hasher()->expects(self::once())->method('hash');

        ($this->equalizer())($this->failure(
            new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()),
            $this->passportForMissingUser(),
        ));

        self::assertSame(CpgUser::class, $this->hasherRequestedFor);
    }

    /**
     * Identifiant inconnu : `UserBadge::getUser()` lève une
     * UserNotFoundException, qu'AuthenticatorManager masque en
     * BadCredentialsException dont le `previous` la conserve
     * (`isSensitiveException()`, expose_security_errors != all).
     */
    public function testAnUnknownIdentifierIsCompensatedByADummyHash(): void
    {
        $this->hasher()->expects(self::once())->method('hash');

        ($this->equalizer())($this->failure(
            new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()),
            $this->passportForMissingUser(),
        ));
    }

    /**
     * Avec `expose_security_errors: all`, l'exception n'est pas masquée et
     * arrive nue : le même compensateur doit s'appliquer.
     */
    public function testABareUserNotFoundExceptionIsCompensatedToo(): void
    {
        $this->hasher()->expects(self::once())->method('hash');

        ($this->equalizer())($this->failure(new UserNotFoundException(), $this->passportForMissingUser()));
    }

    /**
     * Un identifiant inconnu ne doit pas coûter une seconde requête à la base :
     * `UserBadge` ne mémorise pas l'échec, rappeler `getUser()` relancerait le
     * chargeur. Le listener décide donc sur l'exception, sans le rappeler.
     */
    public function testAnUnknownIdentifierDoesNotReloadTheUser(): void
    {
        $this->hasher()->expects(self::once())->method('hash');
        $loaded = 0;

        $passport = new Passport(
            new UserBadge('ghost', static function () use (&$loaded): ?UserInterface {
                ++$loaded;

                return null;
            }),
            new PasswordCredentials('irrelevant'),
        );

        ($this->equalizer())($this->failure(new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()), $passport));

        self::assertSame(0, $loaded, 'Le chargeur d\'utilisateur a été rappelé : une requête SQL de plus par identifiant inconnu.');
    }

    /**
     * Compte invité sans mot de passe : son hachage est vide, donc
     * `NativePasswordHasher::verify()` retombe sur `password_verify($p, '')`,
     * qui échoue sans dériver quoi que ce soit. Sans compensation, une
     * invitation en attente se distingue d'un compte actif au chronomètre.
     */
    public function testAnInvitedAccountWithoutAPasswordIsCompensatedByADummyHash(): void
    {
        $this->hasher()->expects(self::once())->method('hash');

        ($this->equalizer())($this->failure(
            new BadCredentialsException('The presented password is invalid.'),
            $this->passportFor($this->pendingUser()),
        ));
    }

    /**
     * Régression (issue #560) : un compte invité dont un ROLE_SUPER a posé le
     * mot de passe depuis le backoffice (#238, #460) reste en attente
     * d'activation, mais son hachage n'est plus vide — `verify()` a fait la
     * vraie dérivation. Un hachage factice de plus doublait son temps de
     * réponse, et le désignait au chronomètre comme « invitation dont le mot
     * de passe a été posé pour la personne ». Ce qui décide est l'absence de
     * mot de passe, pas l'attente d'activation.
     */
    public function testAPendingAccountWhosePasswordWasSetFromTheBackofficeIsNotCompensated(): void
    {
        $this->hasher()->expects(self::never())->method('hash');
        $user = $this->pendingUser();
        $user->setPassword('a-real-hash-set-from-the-backoffice');

        ($this->equalizer())($this->failure(
            new BadCredentialsException('The presented password is invalid.'),
            $this->passportFor($user),
        ));
    }

    /**
     * Le cas nominal du mauvais mot de passe : la vraie vérification a eu
     * lieu, en ajouter une seconde doublerait le temps de réponse et
     * recréerait l'écart dans l'autre sens.
     */
    public function testAnActiveAccountWithAWrongPasswordIsNotCompensated(): void
    {
        $this->hasher()->expects(self::never())->method('hash');

        ($this->equalizer())($this->failure(
            new BadCredentialsException('The presented password is invalid.'),
            $this->passportFor(new CpgUser('jane', 'hashed-password')),
        ));
    }

    /**
     * Décision de l'issue #590, pincée ici : un compte dont le hachage est
     * d'avant la configuration courante (`needsRehash()` vrai) n'est pas
     * compensé, comme tout compte qui a un mot de passe — l'égaliseur ne lit
     * pas le format du hachage. Ce test ne prouve pas que `verify()` ne fait
     * qu'une dérivation (le hasher est simulé) : c'est le rôle de
     * `CpgUserPasswordHasherTest`, qui rougissait sous `algorithm: auto`. Il
     * fige le choix de ne pas compenser l'écart qui reste (coût stocké contre
     * coût configuré, limite acceptée) : payer la différence obligerait à lire
     * le hachage stocké et ne marcherait que dans un sens. Le hachage n'est
     * qu'une chaîne au format bcrypt, jamais vérifiée.
     */
    public function testAnAccountWhoseHashNeedsRehashIsNotCompensated(): void
    {
        $this->hasher()->expects(self::never())->method('hash');

        ($this->equalizer())($this->failure(
            new BadCredentialsException('The presented password is invalid.'),
            $this->passportFor(new CpgUser('jane', '$2y$05$'.str_repeat('a', 53))),
        ));
    }

    /**
     * Le throttling rejette avant toute vérification (CheckPassportEvent,
     * priorité 2080) : sa réponse doit rester bon marché, c'est son rôle.
     * Le chargeur d'utilisateur ne doit pas non plus être déclenché.
     */
    public function testAThrottledAttemptIsNeverCompensated(): void
    {
        $this->hasher()->expects(self::never())->method('hash');
        $loaded = 0;

        $passport = new Passport(
            new UserBadge('jane', function () use (&$loaded): UserInterface {
                ++$loaded;

                return $this->pendingUser();
            }),
            new PasswordCredentials('irrelevant'),
        );

        ($this->equalizer())($this->failure(new TooManyLoginAttemptsAuthenticationException(), $passport));

        self::assertSame(0, $loaded, 'Le throttling a déclenché un chargement d\'utilisateur.');
    }

    /**
     * Issue #588 : un mot de passe au-delà de la borne du hasher est refusé
     * par OverlongPasswordRefusalListener *avant* le chargement du compte,
     * pour tous les identifiants. Rien n'a été dérivé et rien ne doit l'être :
     * c'est le même chemin pour tous. Rappeler le chargeur coûterait une
     * requête SQL et ne compenserait que l'identifiant inconnu — l'écart que
     * ce refus supprime reviendrait.
     */
    public function testAnOverlongPasswordRefusalIsNeverCompensatedNorLoadsTheAccount(): void
    {
        $this->hasher()->expects(self::never())->method('hash');
        $loaded = 0;

        $passport = new Passport(
            new UserBadge('ghost', static function () use (&$loaded): ?UserInterface {
                ++$loaded;

                return null;
            }),
            new PasswordCredentials('irrelevant'),
        );

        ($this->equalizer())($this->failure(new SubmittedPasswordTooLongException(), $passport));

        self::assertSame(0, $loaded, 'Le refus d\'un mot de passe trop long a déclenché un chargement de compte.');
    }

    /**
     * Le firewall `api` ré-authentifie le JWT à chaque requête et dispatche le
     * même événement : compenser là reviendrait à payer un hachage sur chaque
     * cookie expiré, alors qu'aucun identifiant n'y est comparé.
     */
    public function testAFailureOnAnotherFirewallIsIgnored(): void
    {
        $this->hasher()->expects(self::never())->method('hash');

        ($this->equalizer())(new LoginFailureEvent(
            new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()),
            self::createStub(AuthenticatorInterface::class),
            Request::create('/api/me'),
            null,
            'api',
            $this->passportForMissingUser(),
        ));
    }

    /**
     * Mot de passe (ou identifiant) vide : JsonLoginAuthenticator::getCredentials()
     * lève avant même de construire le passeport, donc aucune base n'est
     * interrogée et aucun hachage n'a lieu — pour *tous* les identifiants.
     * Ce chemin rapide est uniforme, compenser y réintroduirait un écart.
     */
    public function testAFailureWithoutPassportIsNotCompensated(): void
    {
        $this->hasher()->expects(self::never())->method('hash');

        ($this->equalizer())($this->failure(new BadCredentialsException('The key "password" must not be empty.'), null));
    }

    /**
     * Robustesse : si l'exception ne porte pas la trace du compte introuvable
     * mais que le chargeur, rappelé, lève à son tour, on compense plutôt que
     * de laisser fuir l'écart — et surtout on ne casse pas la réponse 401.
     */
    public function testAUserLoaderRaisingUserNotFoundIsCompensated(): void
    {
        $this->hasher()->expects(self::once())->method('hash');

        ($this->equalizer())($this->failure(
            new BadCredentialsException('Authentication failed.'),
            $this->passportForMissingUser(),
        ));
    }

    /**
     * Une panne du chargeur (base injoignable…) ne doit ni compenser ni
     * remonter : le 401 est déjà construit, le listener n'a pas à le changer.
     */
    public function testAFailingUserLoaderNeitherCompensatesNorThrows(): void
    {
        $this->hasher()->expects(self::never())->method('hash');

        $passport = new Passport(
            new UserBadge('jane', static function (): UserInterface {
                throw new AuthenticationServiceException('Base injoignable.');
            }),
            new PasswordCredentials('irrelevant'),
        );

        ($this->equalizer())($this->failure(new BadCredentialsException('Authentication failed.'), $passport));
    }

    /**
     * Le palier de base (GuestUser) n'a pas de mot de passe et ne passe jamais
     * par ce firewall : un utilisateur qui n'est pas un CpgUser ne déclenche
     * rien.
     */
    public function testAUserThatIsNotACpgUserIsIgnored(): void
    {
        $this->hasher()->expects(self::never())->method('hash');

        $user = self::createStub(UserInterface::class);

        ($this->equalizer())($this->failure(
            new BadCredentialsException('The presented password is invalid.'),
            new Passport(new UserBadge('guest', static fn (): UserInterface => $user), new PasswordCredentials('irrelevant')),
        ));
    }

    private function pendingUser(): CpgUser
    {
        $user = new CpgUser('invited', CpgUser::NO_PASSWORD);
        $user->markInvited(new DateTimeImmutable());

        return $user;
    }

    private function passportFor(UserInterface $user): Passport
    {
        return new Passport(new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user), new PasswordCredentials('irrelevant'));
    }

    private function passportForMissingUser(): Passport
    {
        return new Passport(new UserBadge('ghost', static fn (): ?UserInterface => null), new PasswordCredentials('irrelevant'));
    }

    private function failure(AuthenticationException $exception, ?Passport $passport): LoginFailureEvent
    {
        return new LoginFailureEvent(
            $exception,
            self::createStub(AuthenticatorInterface::class),
            Request::create('/api/login_check', 'POST'),
            null,
            self::FIREWALL,
            $passport,
        );
    }
}
