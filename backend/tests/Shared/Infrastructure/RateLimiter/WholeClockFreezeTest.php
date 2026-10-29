<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\RateLimiter;

use App\Tests\Support\FreezesTheWholeClock;
use App\Tests\Support\TestCredentials;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;
use Symfony\Component\Security\Http\EventListener\LoginThrottlingListener;

/**
 * Le `Retry-After` d'un test ne dépend plus de la seconde où il s'exécute
 * (issue #743).
 *
 * Le limiteur de Symfony et `LoginThrottlingListener` lisent l'heure réelle,
 * sans horloge injectable ; seuls `RetryAfterListener` et
 * `LoginThrottlingRefusalListener` lisent `ClockInterface`, que `mockTime()`
 * fige. Avec l'une figée et l'autre non, l'écart entre les deux faisait varier
 * le délai d'une seconde — d'une minute entière pour un login, dont le délai
 * est compté en minutes entières.
 *
 * `ClockMock` n'agit que dans les espaces de noms que `tests/bootstrap.php`
 * enregistre avant qu'un premier test n'ait appelé `microtime()` : PHP retient
 * la fonction résolue à chaque site d'appel, un enregistrement tardif serait
 * sans effet. Ces tests le prouvent sur les vrais composants, sans HTTP, et
 * disent aussi ce qui n'est **pas** mocké : une durée mesurée pour de vrai
 * (verrous, caches, égaliseur de durée) garde l'heure réelle.
 */
final class WholeClockFreezeTest extends TestCase
{
    use FreezesTheWholeClock;

    /** Le quota horaire de l'assistant (rate_limiter.yaml, `career_assistant`). */
    private const int QUOTA = 30;

    private const int HOUR_IN_SECONDS = 3600;

    public function testBothClocksReadTheSameInstant(): void
    {
        $at = self::freezeTheWholeClock();

        // L'horloge des écouteurs du projet…
        self::assertSame($at->format('U.u'), Clock::get()->now()->format('U.u'));
        // … et celle de `time()` / `microtime()` dans les composants de Symfony.
        self::assertSame($at->getTimestamp(), ClockMock::time());
        self::assertSame((float) $at->format('U.u'), ClockMock::microtime(true));
    }

    /**
     * Le refus d'un quota horaire pose son échéance à la fin de la fenêtre,
     * une heure après l'instant figé (arrondie à la seconde supérieure) — et
     * nulle part ailleurs : sur l'heure réelle, elle tomberait des semaines
     * plus loin (ou plus tôt), selon le jour où la suite s'exécute.
     */
    public function testTheDeadlineOfAHourlyQuotaRefusalIsReadOnTheFrozenClock(): void
    {
        $at = self::freezeTheWholeClock();
        $limiter = (new RateLimiterFactory(
            ['id' => 'frozen', 'policy' => 'sliding_window', 'limit' => self::QUOTA, 'interval' => '1 hour'],
            new InMemoryStorage(),
        ))->create('account');

        for ($call = 1; $call <= self::QUOTA; ++$call) {
            self::assertTrue($limiter->consume()->isAccepted(), \sprintf('L\'appel n°%d est refusé avant le quota.', $call));
        }
        $refusal = $limiter->consume();

        self::assertFalse($refusal->isAccepted());
        $deadline = $refusal->getRetryAfter()->getTimestamp();
        self::assertGreaterThan($at->getTimestamp(), $deadline, 'L\'échéance n\'est pas postérieure à l\'instant figé.');
        // Valeur exacte, dérivée ici et nulle part ailleurs (AnswerControllerTest
        // et backend-outillage.md y renvoient). La fenêtre glissante se ferme
        // 3600 s après l'instant figé (12:00:00.999 -> 13:00:00.999) et
        // symfony/rate-limiter >= 8.1.10 arrondit l'échéance à la seconde
        // supérieure, 13:00:01. RetryAfterListener arrondit par excès l'écart
        // restant, 3600,001 s : 3601. Cette seconde au-dessus du délai réel est
        // celle d'un en-tête en secondes entières ; annoncer 3600 ferait
        // réessayer un millième trop tôt.
        self::assertSame($at->getTimestamp() + self::HOUR_IN_SECONDS + 1, $deadline, 'L\'échéance n\'est pas celle de la fin de fenêtre, arrondie à la seconde supérieure.');
    }

    /**
     * `LoginThrottlingListener` compte ses minutes avec `time()` : une échéance
     * posée 15 minutes après l'instant figé se lit 15 minutes, exactement,
     * quelle que soit la fraction de seconde où l'on est.
     */
    public function testTheMinutesOfALoginRefusalAreCountedOnTheFrozenClock(): void
    {
        $at = self::freezeTheWholeClock();
        $deadline = $at->setTimestamp($at->getTimestamp() + 15 * 60);

        $listener = new LoginThrottlingListener(
            $this->requestStackWithARequest(),
            new readonly class($deadline) implements RequestRateLimiterInterface {
                public function __construct(private DateTimeImmutable $deadline)
                {
                }

                public function consume(Request $request): RateLimit
                {
                    return new RateLimit(0, $this->deadline, false, 5);
                }

                public function reset(Request $request): void
                {
                }
            },
        );

        try {
            $listener->checkPassport(new CheckPassportEvent(
                self::createStub(AuthenticatorInterface::class),
                new Passport(new UserBadge('someone'), new PasswordCredentials(TestCredentials::plainPassword())),
            ));
            self::fail('Le refus de débit n\'a pas été levé.');
        } catch (TooManyLoginAttemptsAuthenticationException $refusal) {
            self::assertSame(15, $refusal->getMessageData()['%minutes%']);
        }
    }

    /**
     * Les seuls espaces de noms mockés sont ceux dont le calcul d'une échéance
     * dépend. Les autres gardent l'heure réelle : `usleep()` y dort pour de
     * vrai (verrous Postgres, barrière des tests de concurrence), `hrtime()` y
     * mesure une vraie durée (cache, égaliseur de durée de connexion).
     */
    public function testOnlyTheNamespacesWhoseDeadlinesAreComputedAreMocked(): void
    {
        foreach (['Symfony\Component\RateLimiter\Policy', 'Symfony\Component\Security\Http\EventListener'] as $mocked) {
            self::assertTrue(\function_exists($mocked.'\microtime'), \sprintf('%s n\'est pas enregistré dans tests/bootstrap.php : son microtime() lit l\'heure réelle.', $mocked));
            self::assertTrue(\function_exists($mocked.'\time'), \sprintf('%s n\'est pas enregistré dans tests/bootstrap.php : son time() lit l\'heure réelle.', $mocked));
        }

        foreach ([
            'Symfony\Component\Lock',
            'Symfony\Component\Lock\Store',
            'Symfony\Component\Cache\Adapter',
            'Symfony\Component\RateLimiter',
            'Symfony\Component\RateLimiter\Storage',
            'App\Security\Authentication\Infrastructure\Security',
            'App\Shared\Infrastructure\Http',
            'App\Tests\Security\Authentication',
        ] as $untouched) {
            foreach (['microtime', 'time', 'usleep', 'sleep', 'hrtime'] as $function) {
                self::assertFalse(\function_exists($untouched.'\\'.$function), \sprintf('%s\%s est mocké : une durée réellement mesurée ou attendue y serait faussée.', $untouched, $function));
            }
        }
    }

    private function requestStackWithARequest(): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/api/login_check', 'POST'));

        return $stack;
    }
}
