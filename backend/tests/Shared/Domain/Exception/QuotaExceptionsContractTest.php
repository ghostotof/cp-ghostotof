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
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Le contrat de toute exception de quota de src/, c'est-à-dire de toute
 * RetryAfterAware concrète, recensée par jetons (DeclaredClasses) plutôt que
 * tenue à la main : un quota ajouté sans l'un des deux termes rougit ici.
 *
 *  - Son `Retry-After` (issue #273) tient à un seul `implements` : le retirer
 *    fait disparaître l'en-tête sans autre erreur. L'échéance transmise au
 *    constructeur doit être celle que l'interface expose.
 *  - Son `type` (issue #369) est `/errors/rate-limited`, statut 429, celui des
 *    zones nginx : ProblemExceptionInterface + IsRateLimitedProblem.
 *
 * Les tests fonctionnels de chaque route verraient l'un ou l'autre, mais au
 * milieu d'un scénario de vingt requêtes ; ce test le dit en une ligne.
 */
final class QuotaExceptionsContractTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../src';

    /**
     * L'exception est construite avec l'échéance pour seul argument, la
     * signature de tous les quotas : un quota qui en aurait une autre adapte
     * ce test plutôt que de s'y soustraire.
     *
     * @param class-string<RetryAfterAware> $class
     */
    #[DataProvider('quotaExceptions')]
    public function testEveryQuotaExceptionExposesItsDeadline(string $class): void
    {
        $deadline = new DateTimeImmutable('2026-10-02 12:00:42');

        $exception = (new ReflectionClass($class))->newInstance($deadline);

        self::assertSame($deadline, $exception->retryAfter);
    }

    /**
     * Instanciée sans son constructeur : getType() et getStatus() ne
     * dépendent que de la classe.
     *
     * @param class-string<RetryAfterAware> $class
     */
    #[DataProvider('quotaExceptions')]
    public function testEveryQuotaExceptionIsARateLimitedProblem(string $class): void
    {
        $exception = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertInstanceOf(ProblemExceptionInterface::class, $exception, 'Une exception de quota implémente ProblemExceptionInterface et utilise IsRateLimitedProblem (modèle : BaseAccessRateLimitExceededException).');
        self::assertSame('/errors/rate-limited', $exception->getType());
        self::assertSame(429, $exception->getStatus());
    }

    /**
     * Un recensement qui ne trouverait rien laisserait les deux tests
     * ci-dessus verts pour rien : les quotas connus doivent y figurer.
     */
    public function testTheInventoryFindsTheKnownQuotas(): void
    {
        $found = array_keys(iterator_to_array(self::quotaExceptions()));

        foreach ([
            ContactRateLimitExceededException::class,
            PasswordSetupRateLimitExceededException::class,
            BaseAccessRateLimitExceededException::class,
            TranslationRateLimitExceededException::class,
            AssistantRateLimitExceededException::class,
        ] as $known) {
            self::assertContains($known, $found);
        }
    }

    /**
     * @return iterable<class-string<RetryAfterAware>, array{class-string<RetryAfterAware>}>
     */
    public static function quotaExceptions(): iterable
    {
        foreach (DeclaredClasses::implementing(self::SOURCES, RetryAfterAware::class) as $class) {
            if (!(new ReflectionClass($class))->isAbstract()) {
                yield $class => [$class];
            }
        }
    }
}
