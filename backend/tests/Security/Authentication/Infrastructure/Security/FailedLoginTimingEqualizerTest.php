<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\FailedLoginTimingEqualizer;
use App\Security\User\Domain\Entity\CpgUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
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
     * Compte invité non activé : son hachage est vide, donc
     * `NativePasswordHasher::verify()` retombe sur `password_verify($p, '')`,
     * qui échoue sans dériver quoi que ce soit. Sans compensation, une
     * invitation en attente se distingue d'un compte actif au chronomètre.
     */
    public function testAnAccountPendingActivationIsCompensatedByADummyHash(): void
    {
        $this->hasher()->expects(self::once())->method('hash');

        ($this->equalizer())($this->failure(
            new BadCredentialsException('The presented password is invalid.'),
            $this->passportFor($this->pendingUser()),
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
        $user = new CpgUser('invited', '');
        $user->markInvited(new \DateTimeImmutable());

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
