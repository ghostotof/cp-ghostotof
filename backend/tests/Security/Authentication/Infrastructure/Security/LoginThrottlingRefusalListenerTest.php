<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Domain\Exception\LoginRateLimitExceededException;
use App\Security\Authentication\Infrastructure\Security\InvalidLoginThrottlingWindowException;
use App\Security\Authentication\Infrastructure\Security\LoginThrottlingRefusalListener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Le tri de LoginThrottlingRefusalListener (issue #399) : seul le refus de
 * `login_throttling` du firewall `login` devient une exception de quota, et son
 * échéance suit les minutes que Symfony a tirées du limiteur, plafonnées à la
 * fenêtre du limiteur (issue #743). LoginThrottlingTest couvre le câblage de
 * bout en bout, LoginThrottlingIntervalAlignmentTest l'accord du plafond avec
 * security.yaml.
 */
final class LoginThrottlingRefusalListenerTest extends TestCase
{
    private const string NOW = '2026-10-10 12:00:00';

    /** La fenêtre de `login_throttling`, telle que l'écouteur la reçoit. */
    private const int WINDOW_MINUTES = 15;

    public function testTheThrottlingRefusalBecomesAQuotaExceptionDueAfterTheLimitersMinutes(): void
    {
        try {
            $this->listener()($this->failure(new TooManyLoginAttemptsAuthenticationException(15)));
            self::fail('Le refus de login_throttling aurait dû lever LoginRateLimitExceededException.');
        } catch (LoginRateLimitExceededException $exception) {
            self::assertSame('2026-10-10 12:15:00', $exception->retryAfter->format('Y-m-d H:i:s'));
            self::assertSame(429, $exception->getStatus());
            self::assertSame('/errors/rate-limited', $exception->getType());
        }
    }

    /**
     * Issue #743 : l'échéance est plafonnée à la fenêtre du limiteur, dont
     * Symfony lit parfois 901 s, donc 16 minutes (le calcul est dans le
     * docblock de LoginThrottlingRefusalListener).
     *
     * @return iterable<string, array{0: int, 1: string}>
     */
    public static function provideMinutesAndTheirCappedDeadline(): iterable
    {
        yield 'la fenêtre entière' => [15, '2026-10-10 12:15:00'];
        yield 'une minute de trop (901 s arrondies par Symfony)' => [16, '2026-10-10 12:15:00'];
        yield 'très au-delà de la fenêtre' => [120, '2026-10-10 12:15:00'];
        yield 'sous le plafond' => [3, '2026-10-10 12:03:00'];
        yield 'une minute' => [1, '2026-10-10 12:01:00'];
    }

    #[DataProvider('provideMinutesAndTheirCappedDeadline')]
    public function testTheDeadlineNeverExceedsTheLimitersWindow(int $minutes, string $expectedDeadline): void
    {
        try {
            $this->listener()($this->failure(new TooManyLoginAttemptsAuthenticationException($minutes)));
            self::fail('Le refus de login_throttling aurait dû lever LoginRateLimitExceededException.');
        } catch (LoginRateLimitExceededException $exception) {
            self::assertSame($expectedDeadline, $exception->retryAfter->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Un plafond nul ou négatif annoncerait un `Retry-After` absurde à chaque
     * refus : le paramètre mal posé échoue à la construction, nommé, plutôt que
     * sous les coups d'un attaquant.
     *
     * @return iterable<string, array{0: int}>
     */
    public static function provideWindowsThatAreNotADuration(): iterable
    {
        yield 'zéro' => [0];
        yield 'moins une minute' => [-1];
        yield 'très négative' => [-15];
    }

    #[DataProvider('provideWindowsThatAreNotADuration')]
    public function testAWindowShorterThanOneMinuteIsRefusedAtConstruction(int $windowMinutes): void
    {
        $this->expectException(InvalidLoginThrottlingWindowException::class);
        $this->expectExceptionMessage(\sprintf('%d reçue', $windowMinutes));

        new LoginThrottlingRefusalListener(new MockClock(self::NOW), $windowMinutes);
    }

    public function testAOneMinuteWindowIsTheSmallestAccepted(): void
    {
        try {
            $this->listener(1)($this->failure(new TooManyLoginAttemptsAuthenticationException(5)));
            self::fail('Le refus de login_throttling aurait dû lever LoginRateLimitExceededException.');
        } catch (LoginRateLimitExceededException $exception) {
            self::assertSame('2026-10-10 12:01:00', $exception->retryAfter->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Jamais de `previous` : l'ExceptionListener du firewall (kernel.exception,
     * priorité 1) parcourt toute la chaîne et reprend la première
     * AuthenticationException qu'il y trouve. La cause chaînée lui rendrait la
     * main, et le refus redeviendrait un 401 du point d'entrée, sans Retry-After.
     */
    public function testTheQuotaExceptionNeverChainsTheAuthenticationFailure(): void
    {
        try {
            $this->listener()($this->failure(new TooManyLoginAttemptsAuthenticationException(15)));
            self::fail('Le refus de login_throttling aurait dû lever LoginRateLimitExceededException.');
        } catch (LoginRateLimitExceededException $exception) {
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * `TooManyLoginAttemptsAuthenticationException` accepte un seuil absent,
     * et Symfony calcule 0 quand l'échéance tombe sur la seconde courante : un
     * `Retry-After: 0` dirait de réessayer tout de suite, une minute est sûre.
     *
     * @return iterable<string, array{0: ?int}>
     */
    public static function provideUnusableThresholds(): iterable
    {
        yield 'seuil absent' => [null];
        yield 'seuil nul' => [0];
    }

    #[DataProvider('provideUnusableThresholds')]
    public function testAnUnusableThresholdFallsBackToOneMinute(?int $threshold): void
    {
        try {
            $this->listener()($this->failure(new TooManyLoginAttemptsAuthenticationException($threshold)));
            self::fail('Le refus de login_throttling aurait dû lever LoginRateLimitExceededException.');
        } catch (LoginRateLimitExceededException $exception) {
            self::assertSame('2026-10-10 12:01:00', $exception->retryAfter->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Un mot de passe faux garde le 401 de Lexik (non-énumération, audit A10).
     * L'écouteur n'écrit jamais la réponse : le seul moyen qu'il a de la
     * changer est de lever, et le contrat est donc qu'il ne lève pas.
     */
    public function testAnOrdinaryFailureRaisesNoQuotaRefusal(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener()($this->failure(new BadCredentialsException('Bad credentials.')));
    }

    /** Le firewall `api` ré-authentifie le JWT, il ne connaît pas `login_throttling`. */
    public function testAnotherFirewallRaisesNoQuotaRefusal(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener()($this->failure(new TooManyLoginAttemptsAuthenticationException(15), 'api'));
    }

    private function listener(int $windowMinutes = self::WINDOW_MINUTES): LoginThrottlingRefusalListener
    {
        return new LoginThrottlingRefusalListener(new MockClock(self::NOW), $windowMinutes);
    }

    private function failure(AuthenticationException $exception, string $firewall = 'login'): LoginFailureEvent
    {
        return new LoginFailureEvent(
            $exception,
            self::createStub(AuthenticatorInterface::class),
            Request::create('/api/login_check', 'POST'),
            new Response('{"code":401,"message":"Invalid credentials."}', 401),
            $firewall,
        );
    }
}
