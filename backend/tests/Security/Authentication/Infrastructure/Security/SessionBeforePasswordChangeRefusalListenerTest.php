<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\SessionBeforePasswordChangeRefusalListener;
use App\Security\Authentication\Infrastructure\Security\SessionPredatesPasswordChangeException;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\ValueObject\BaseAccessUser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * La règle du refus (#650, R1b) sur des passeports écrits à la main : ce que le
 * test HTTP ne peut pas produire — le fournisseur de jetons de Lexik pose
 * toujours un `iat` — et les frontières à la seconde, sans horloge. Le
 * parcours réel est dans `SessionBeforePasswordChangeTest`.
 */
final class SessionBeforePasswordChangeRefusalListenerTest extends TestCase
{
    private const int CHANGED_AT = 1_793_000_000;

    #[DataProvider('boundaries')]
    public function testItRefusesASessionOpenedAtOrBeforeTheChangeOnly(int $issuedAt, bool $refused): void
    {
        $listener = new SessionBeforePasswordChangeRefusalListener();
        $event = $this->eventFor($this->accountChangedAt(self::CHANGED_AT), ['iat' => $issuedAt]);

        if ($refused) {
            $this->expectException(SessionPredatesPasswordChangeException::class);
        }
        $listener($event);

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function boundaries(): iterable
    {
        yield 'une heure avant' => [self::CHANGED_AT - 3600, true];
        yield 'une seconde avant' => [self::CHANGED_AT - 1, true];
        yield 'la même seconde' => [self::CHANGED_AT, true];
        yield 'une seconde après' => [self::CHANGED_AT + 1, false];
    }

    /** @param array<string, mixed>|null $payload */
    #[DataProvider('unreadableInstants')]
    public function testAnInstantItCannotEstablishIsTakenForEarlier(?array $payload): void
    {
        $listener = new SessionBeforePasswordChangeRefusalListener();

        $this->expectException(SessionPredatesPasswordChangeException::class);
        $listener($this->eventFor($this->accountChangedAt(self::CHANGED_AT), $payload));
    }

    /** @return iterable<string, array{array<string, mixed>|null}> */
    public static function unreadableInstants(): iterable
    {
        yield 'aucun payload' => [null];
        yield 'aucun iat' => [['username' => 'jane']];
        yield 'iat en chaîne' => [['iat' => (string) (self::CHANGED_AT + 100)]];
        yield 'iat décimal' => [['iat' => (float) (self::CHANGED_AT + 100)]];
    }

    /** Sans changement de mot de passe, rien à comparer : jamais refusé, même sans instant. */
    public function testAnAccountThatNeverChangedItsPasswordIsNeverRefused(): void
    {
        $listener = new SessionBeforePasswordChangeRefusalListener();

        $listener($this->eventFor(new CpgUser('jane', 'hashed-password'), null));
        $listener($this->eventFor(new CpgUser('jane', 'hashed-password'), ['iat' => 1]));

        $this->addToAssertionCount(1);
    }

    /** L'accès de base n'a pas de compte : jamais refusé, quel que soit son payload. */
    public function testTheBaseAccessUserIsNeverConcerned(): void
    {
        $listener = new SessionBeforePasswordChangeRefusalListener();

        $listener($this->eventFor(new BaseAccessUser('guest-0123'), null));
        $listener($this->eventFor(new BaseAccessUser('guest-0123'), ['iat' => 1, 'guest' => true]));

        $this->addToAssertionCount(1);
    }

    private function accountChangedAt(int $timestamp): CpgUser
    {
        $account = new CpgUser('jane', 'hashed-password');
        $account->replacePassword('another-hash', new DateTimeImmutable('@'.$timestamp));

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
