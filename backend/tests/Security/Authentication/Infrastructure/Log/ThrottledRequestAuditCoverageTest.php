<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Log;

use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Log\ThrottledRequestAuditListener;
use App\Shared\Domain\Exception\RetryAfterAware;
use App\Tests\Security\Authentication\Infrastructure\Log\Fixtures\QuotaSources\UnauditedQuotaFixtureException;
use App\Tests\Support\DeclaredClasses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Toute exception de quota de src/ est soit tracée sur `security_audit` par
 * ThrottledRequestAuditListener, soit justifiée comme quota par compte
 * (issue #361).
 *
 * L'écouteur trie en liste fermée, quand RetryAfterListener lit
 * RetryAfterAware de façon générique : un nouveau limiteur anonyme aurait son
 * `Retry-After` sans aucun événement d'audit, et rien ne rougissait. Son
 * exception risquait en plus de passer en `info` dans `framework.exceptions`
 * « puisque tracée », comme les trois premières.
 *
 * Le recensement passe par les RetryAfterAware déclarées dans src/ (lues par
 * jetons, DeclaredClasses), et le tri par l'écouteur réel : on lui soumet
 * chaque exception et on relève ce qu'il écrit. Une interface qui porterait le
 * nom de l'événement aurait supprimé la liste fermée, mais fait dépendre
 * chaque domaine (Contact compris) du vocabulaire du journal de Security, et
 * demandé une seconde interface pour écarter les quotas par compte.
 */
final class ThrottledRequestAuditCoverageTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../../src';
    private const string FIXTURE_SOURCES = __DIR__.'/Fixtures/QuotaSources';

    /**
     * Quotas qui ne relèvent pas du journal de sécurité, chacun avec sa raison.
     * C'est la seule échappatoire, et elle se voit.
     */
    private const array PER_ACCOUNT = [
        TranslationRateLimitExceededException::class => 'Quota par compte ROLE_SUPER (ADR 0004 D5) : le refus est tracé avec le compte sur `ai_usage` (issue #356), pas sur un journal de requêtes anonymes.',
        AssistantRateLimitExceededException::class => 'Quota par compte ROLE_TRUSTED (spec 0005 D6) : le refus est tracé avec le compte sur `ai_usage` par QuotaGuardedCareerAssistant.',
    ];

    public function testEveryQuotaExceptionIsAuditedOrJustified(): void
    {
        $quotas = $this->quotaExceptions(self::SOURCES);
        self::assertNotEmpty($quotas, 'Aucune exception de quota recensée : le garde-fou ne garderait rien.');

        self::assertSame(
            [],
            $this->unaudited(array_values(array_diff($quotas, array_keys(self::PER_ACCOUNT)))),
            'Exception de quota sans événement sur `security_audit` : la trier dans ThrottledRequestAuditListener, ou la justifier dans PER_ACCOUNT.',
        );
    }

    /**
     * Une justification ne vaut que pour un quota qui existe et que
     * l'écouteur ignore bel et bien : sinon elle ment, ou un quota par compte
     * est entré dans le journal de sécurité.
     */
    public function testEveryJustifiedQuotaExistsAndStaysOutOfTheSecurityAudit(): void
    {
        $quotas = $this->quotaExceptions(self::SOURCES);

        self::assertSame([], array_values(array_diff(array_keys(self::PER_ACCOUNT), $quotas)), 'Justification d\'une exception de quota qui n\'existe plus : la retirer.');

        foreach (array_keys(self::PER_ACCOUNT) as $class) {
            self::assertSame([], $this->eventsFor($class), $class.' est justifiée comme quota par compte, mais l\'écouteur la trace sur `security_audit`.');
        }
    }

    /**
     * Chaque quota tracé l'est par un seul événement, qui n'appartient qu'à
     * lui : rattacher un nouveau limiteur à `contact-throttled` par
     * copier-coller rendrait le garde-fou vert et le journal faux.
     */
    public function testEveryAuditedQuotaHasItsOwnEvent(): void
    {
        $events = [];
        foreach (array_diff($this->quotaExceptions(self::SOURCES), array_keys(self::PER_ACCOUNT)) as $class) {
            $classEvents = $this->eventsFor($class);
            self::assertCount(1, $classEvents, $class.' doit donner exactement un événement.');
            $events[$class] = $classEvents[0];
        }

        self::assertSame(array_values($events), array_values(array_unique($events)), 'Deux quotas partagent un même événement d\'audit.');
    }

    /**
     * Le garde-fou lui-même, sur un répertoire fixture : un quatrième quota
     * ajouté sans rattachement doit être vu par le recensement, puis signalé.
     * Sans cette preuve, le premier test pourrait être vert pour de mauvaises
     * raisons.
     */
    public function testAQuotaAddedWithoutAnEventIsFlagged(): void
    {
        $quotas = $this->quotaExceptions(self::FIXTURE_SOURCES);

        self::assertSame([UnauditedQuotaFixtureException::class], $quotas);
        self::assertSame([UnauditedQuotaFixtureException::class], $this->unaudited($quotas));
    }

    /**
     * @return list<class-string<RetryAfterAware>> exceptions de quota concrètes déclarées dans le répertoire
     */
    private function quotaExceptions(string $directory): array
    {
        return array_values(array_filter(
            DeclaredClasses::implementing($directory, RetryAfterAware::class),
            static fn (string $class): bool => !new \ReflectionClass($class)->isAbstract(),
        ));
    }

    /**
     * @param list<class-string> $classes
     *
     * @return list<class-string> celles pour lesquelles l'écouteur n'écrit rien
     */
    private function unaudited(array $classes): array
    {
        return array_values(array_filter($classes, fn (string $class): bool => [] === $this->eventsFor($class)));
    }

    /**
     * Ce que l'écouteur réel écrit sur le journal d'audit quand l'exception
     * remonte d'une requête principale.
     *
     * L'exception est instanciée sans son constructeur : l'écouteur ne trie que
     * sur la classe, et un futur quota n'a aucune raison de partager la
     * signature des trois premiers.
     *
     * @param class-string $class
     *
     * @return list<string> méthodes de SecurityAuditLoggerInterface appelées, dans l'ordre
     */
    private function eventsFor(string $class): array
    {
        $refusal = new \ReflectionClass($class)->newInstanceWithoutConstructor();
        self::assertInstanceOf(\Throwable::class, $refusal);

        $events = [];
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        foreach (get_class_methods(SecurityAuditLoggerInterface::class) as $method) {
            $auditLogger->method($method)->willReturnCallback(static function () use (&$events, $method): void {
                $events[] = $method;
            });
        }

        new ThrottledRequestAuditListener($auditLogger)(new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/test', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $refusal,
        ));

        return $events;
    }
}
