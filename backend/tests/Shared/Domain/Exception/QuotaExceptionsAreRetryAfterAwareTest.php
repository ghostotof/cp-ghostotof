<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\Exception;

use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use App\Shared\Domain\Exception\RetryAfterAware;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Le `Retry-After` de chaque 429 de quota tient à un seul `implements`
 * (issue #273) : le retirer fait disparaître l'en-tête sans autre erreur.
 * Les tests fonctionnels de chaque route le verraient, mais au milieu d'un
 * scénario de vingt requêtes ; ce test le dit en une ligne, et vérifie que
 * l'échéance transmise est bien celle que l'interface expose.
 */
final class QuotaExceptionsAreRetryAfterAwareTest extends TestCase
{
    /**
     * @param Closure(DateTimeImmutable):Throwable $build
     */
    #[DataProvider('quotaExceptions')]
    public function testEveryQuotaExceptionExposesItsDeadline(Closure $build): void
    {
        $deadline = new DateTimeImmutable('2026-10-02 12:00:42');

        $exception = $build($deadline);

        self::assertInstanceOf(RetryAfterAware::class, $exception);
        self::assertSame($deadline, $exception->retryAfter);
    }

    /**
     * @return iterable<string, array{Closure(DateTimeImmutable):Throwable}>
     */
    public static function quotaExceptions(): iterable
    {
        yield 'contact' => [static fn (DateTimeImmutable $d): Throwable => new ContactRateLimitExceededException($d)];
        yield 'set-password' => [static fn (DateTimeImmutable $d): Throwable => new PasswordSetupRateLimitExceededException($d)];
        yield 'accès de base' => [static fn (DateTimeImmutable $d): Throwable => new BaseAccessRateLimitExceededException($d)];
        yield 'traduction' => [static fn (DateTimeImmutable $d): Throwable => new TranslationRateLimitExceededException($d)];
        yield 'assistant' => [static fn (DateTimeImmutable $d): Throwable => new AssistantRateLimitExceededException($d)];
    }
}
