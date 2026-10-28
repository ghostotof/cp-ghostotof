<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Security\SessionBeforePasswordChangeRefusalListener;
use App\Security\Authentication\Infrastructure\Security\SessionPredatesPasswordChangeException;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\ValueObject\BaseAccessUser;
use App\Shared\Domain\Security\CredentialsVersionClaim;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * La règle du refus (#650, R1b, puis S1 : la **version des identifiants** à
 * la place de la date) sur des passeports écrits à la main : ce que le test HTTP
 * ne peut pas produire — le fournisseur de jetons de Lexik, via notre écouteur,
 * pose toujours la revendication —, sans horloge. Le parcours réel est dans
 * `SessionBeforePasswordChangeTest`.
 */
final class SessionBeforePasswordChangeRefusalListenerTest extends TestCase
{
    /** Le compte est à la version 2 ; seule la version 2 est servie. */
    #[DataProvider('claims')]
    public function testItRefusesEveryVersionButTheCurrentOne(mixed $claim, bool $refused): void
    {
        $listener = $this->listener();
        $event = $this->eventFor($this->accountAtVersion(2), [CredentialsVersionClaim::NAME => $claim, 'iat' => 1_793_000_000]);

        if ($refused) {
            $this->expectException(SessionPredatesPasswordChangeException::class);
        }
        $listener($event);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function claims(): iterable
    {
        yield 'la version courante' => [2, false];
        yield 'une version avant' => [1, true];
        yield 'la version zéro' => [0, true];
        yield 'une version après (base restaurée)' => [3, true];
        yield 'en chaîne' => ['2', true];
        yield 'décimale' => [2.0, true];
        yield 'booléenne' => [true, true];
    }

    /**
     * **Échoue fermé** : une revendication présente qui n'est pas un entier n'est
     * pas « absente » (qui vaut 0) mais illisible — refusée, même pour un compte
     * jamais changé. Notre écouteur ne l'écrit jamais ainsi ; seule la
     * signature du site garde ce cas hors d'atteinte.
     */
    #[DataProvider('unreadableClaims')]
    public function testAClaimThatIsNotAnIntegerIsRefusedEvenForAnAccountNeverChanged(mixed $claim): void
    {
        $listener = $this->listener();

        $this->expectException(SessionPredatesPasswordChangeException::class);
        $listener($this->eventFor($this->accountAtVersion(0), [CredentialsVersionClaim::NAME => $claim]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableClaims(): iterable
    {
        yield 'chaîne' => ['0'];
        yield 'décimale' => [0.0];
        yield 'booléenne' => [false];
        yield 'nulle' => [null];
        yield 'tableau' => [[0]];
    }

    /**
     * Le `iat` ne compte plus pour cette décision : la date de la seconde
     * relecture (F-A, F-B) refusait ou servait à tort selon l'horloge et la
     * seconde de la connexion. Un `iat` absent ne change rien.
     */
    public function testTheIssuedAtPlaysNoPart(): void
    {
        $listener = $this->listener();

        $listener($this->eventFor($this->accountAtVersion(1), [CredentialsVersionClaim::NAME => 1]));
        $listener($this->eventFor($this->accountAtVersion(1), [CredentialsVersionClaim::NAME => 1, 'iat' => 1]));

        $this->addToAssertionCount(1);
    }

    /**
     * Un jeton émis avant le déploiement n'a pas de revendication : il vaut la
     * version 0. Servi tant que le mot de passe du compte n'a pas changé (sinon
     * chaque session ouverte au déploiement sauterait), refusé dès qu'il a changé.
     *
     * @param array<string, mixed>|null $payload
     */
    #[DataProvider('payloadsWithoutClaim')]
    public function testATokenWithoutClaimIsVersionZero(?array $payload): void
    {
        $listener = $this->listener();

        $listener($this->eventFor($this->accountAtVersion(0), $payload));
        $this->addToAssertionCount(1);

        $this->expectException(SessionPredatesPasswordChangeException::class);
        $listener($this->eventFor($this->accountAtVersion(1), $payload));
    }

    /** @return iterable<string, array{array<string, mixed>|null}> */
    public static function payloadsWithoutClaim(): iterable
    {
        yield 'aucun payload' => [null];
        yield 'payload sans revendication' => [['username' => 'jane', 'iat' => 1_793_000_000]];
    }

    /** L'accès de base n'a pas de compte : jamais refusé, quel que soit son payload. */
    public function testTheBaseAccessUserIsNeverConcerned(): void
    {
        $listener = $this->listener();

        $listener($this->eventFor(new BaseAccessUser('guest-0123'), null));
        $listener($this->eventFor(new BaseAccessUser('guest-0123'), ['iat' => 1, 'guest' => true]));

        $this->addToAssertionCount(1);
    }

    /**
     * F-C : chaque refus est audité **avant** de lever, avec le compte et la
     * version présentée — 0 quand la revendication manque (la règle la lit
     * ainsi), `null` quand elle est présente sans être un entier. La version
     * courante, elle, est lue sur le compte par le journal.
     */
    /** @param array<string, mixed>|null $payload */
    #[DataProvider('refusedPayloads')]
    public function testEveryRefusalIsAuditedWithThePresentedVersion(?array $payload, ?int $presented): void
    {
        $account = $this->accountAtVersion(2);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('sessionRefusedAfterCredentialsChange')->with($account, $presented);

        try {
            new SessionBeforePasswordChangeRefusalListener($auditLogger)($this->eventFor($account, $payload));
            self::fail('Le refus aurait dû lever.');
        } catch (SessionPredatesPasswordChangeException) {
            // Attendu : la ligne d'audit est écrite avant.
        }
    }

    /** @return iterable<string, array{array<string, mixed>|null, int|null}> */
    public static function refusedPayloads(): iterable
    {
        yield 'une version avant' => [[CredentialsVersionClaim::NAME => 1], 1];
        yield 'une version après' => [[CredentialsVersionClaim::NAME => 3], 3];
        yield 'revendication absente (vaut 0)' => [['username' => 'jane'], 0];
        yield 'aucun payload (vaut 0)' => [null, 0];
        yield 'revendication illisible' => [[CredentialsVersionClaim::NAME => '2'], null];
    }

    /** Une session servie, ou celle de l'accès de base, n'écrit aucune ligne. */
    public function testAServedSessionAndTheBaseAccessAreNeverAudited(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('sessionRefusedAfterCredentialsChange');
        $listener = new SessionBeforePasswordChangeRefusalListener($auditLogger);

        $listener($this->eventFor($this->accountAtVersion(2), [CredentialsVersionClaim::NAME => 2]));
        $listener($this->eventFor(new BaseAccessUser('guest-0123'), null));
    }

    private function listener(): SessionBeforePasswordChangeRefusalListener
    {
        return new SessionBeforePasswordChangeRefusalListener(self::createStub(SecurityAuditLoggerInterface::class));
    }

    private function accountAtVersion(int $version): CpgUser
    {
        $account = new CpgUser('jane', 'hashed-password');
        for ($i = 0; $i < $version; ++$i) {
            $account->replacePassword('hash-'.$i, new DateTimeImmutable('@1793000000'));
        }

        return $account;
    }

    /** @param array<string, mixed>|null $payload */
    private function eventFor(UserInterface $user, ?array $payload): CheckPassportEvent
    {
        $passport = new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn (): UserInterface => $user));
        if (null !== $payload) {
            $passport->setAttribute('payload', $payload);
        }

        return new CheckPassportEvent(self::createStub(AuthenticatorInterface::class), $passport);
    }
}
