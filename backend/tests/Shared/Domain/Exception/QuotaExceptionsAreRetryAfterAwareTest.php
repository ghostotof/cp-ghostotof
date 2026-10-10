<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use App\Shared\Domain\Exception\RetryAfterAware;
use App\Tests\Support\DeclaredClasses;
use Closure;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

/**
 * Le `Retry-After` de chaque 429 de quota tient à un seul `implements`
 * (issue #273) : le retirer fait disparaître l'en-tête sans autre erreur.
 * Les tests fonctionnels de chaque route le verraient, mais au milieu d'un
 * scénario de vingt requêtes ; ce test le dit en une ligne, et vérifie que
 * l'échéance transmise est bien celle que l'interface expose.
 *
 * Il garde aussi le `type` de ce 429 (issue #369) : une seule cause, une
 * seule valeur à reconnaître pour un client.
 */
final class QuotaExceptionsAreRetryAfterAwareTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../src';

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
     * Issue #369 : un refus de débit porte `type: /errors/rate-limited`, celui
     * des zones nginx (`@rate_limited`), quelle que soit la route. Avant, les
     * quotas du contact, de la définition du mot de passe et de la traduction
     * sortaient en `/errors/429`, le `type` qu'API Platform déduit du seul
     * statut d'une exception qui n'en déclare pas.
     *
     * Le recensement lit src/ (DeclaredClasses) plutôt que la liste
     * ci-dessous : un quota ajouté sans ce `type` rougit ici. L'exception est
     * instanciée sans son constructeur, que rien n'oblige un futur quota à
     * partager ; `getType()` et `getStatus()` n'en dépendent pas.
     */
    public function testEveryQuotaExceptionOfSrcIsARateLimitedProblem(): void
    {
        $quotas = array_filter(
            DeclaredClasses::implementing(self::SOURCES, RetryAfterAware::class),
            static fn (string $class): bool => !(new ReflectionClass($class))->isAbstract(),
        );
        self::assertNotEmpty($quotas, 'Aucune exception de quota recensée : le garde-fou ne garderait rien.');

        $untyped = [];
        foreach ($quotas as $class) {
            $exception = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            if (!$exception instanceof ProblemExceptionInterface
                || '/errors/rate-limited' !== $exception->getType()
                || 429 !== $exception->getStatus()) {
                $untyped[] = $class;
            }
        }

        self::assertSame(
            [],
            $untyped,
            'Chaque exception de quota implémente ProblemExceptionInterface avec le type `rate-limited` et le statut 429 (modèle : BaseAccessRateLimitExceededException).',
        );
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
