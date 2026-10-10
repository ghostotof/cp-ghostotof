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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

/**
 * Toute exception de quota de src/ est soit tracée sur `security_audit` par
 * ThrottledRequestAuditListener, soit justifiée comme quota par compte
 * (issue #361).
 *
 * L'écouteur trie en liste fermée, quand RetryAfterListener lit
 * RetryAfterAware de façon générique : un nouveau limiteur anonyme aurait son
 * `Retry-After` sans aucun événement d'audit, et rien ne rougissait. Son
 * exception risquait en plus de passer en `info` dans `framework.exceptions`
 * « puisque tracée », comme celles des quotas déjà triés.
 *
 * Le recensement passe par les RetryAfterAware déclarées dans src/ (lues par
 * jetons, DeclaredClasses), et le tri par l'écouteur réel : on lui soumet
 * chaque exception et on relève les méthodes du journal qu'il appelle. Une
 * interface qui porterait le nom de l'événement aurait supprimé la liste
 * fermée, mais fait dépendre chaque domaine (Contact compris) du vocabulaire
 * du journal de Security, et demandé une seconde interface pour écarter les
 * quotas par compte.
 *
 * Limite : l'écouteur est appelé directement. Le test prouve le tri, pas que
 * l'exception remonte jusqu'à lui en priorité 0 sans être enveloppée — c'est
 * le rôle de SecurityAuditLogTest, sur le câblage réel.
 */
final class ThrottledRequestAuditCoverageTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../../src';
    private const string FIXTURE_SOURCES = __DIR__.'/Fixtures/QuotaSources';

    /**
     * Quotas qui ne relèvent pas du journal de sécurité, chacun avec sa raison
     * et le test qui fige sa trace. C'est la seule échappatoire, et elle se voit.
     */
    private const array PER_ACCOUNT = [
        TranslationRateLimitExceededException::class => 'Quota par compte ROLE_SUPER (ADR 0004 D5) : le refus est tracé avec le compte sur `ai_usage` par SymfonyTranslationRateLimiter (SymfonyTranslationRateLimiterTest, BackofficeTranslationResourceTest).',
        AssistantRateLimitExceededException::class => 'Quota par compte ROLE_TRUSTED (spec 0005 D6) : le refus est tracé avec le compte sur `ai_usage` par QuotaGuardedCareerAssistant (QuotaGuardedCareerAssistantTest, AnswerControllerTest).',
    ];

    /**
     * Un événement de quota se nomme `…Throttled` (`<route>-throttled` dans le
     * journal). Ceux-ci portent ce suffixe sans être un quota de requête.
     */
    private const array NOT_QUOTA_EVENTS = [
        'loginThrottled' => 'login_throttling de Symfony : échecs de connexion comptés par identifiant, réponse 401, émis par SecurityEventsSubscriber.',
    ];

    /**
     * Recensements déjà faits, par répertoire : src/ n'est lu qu'une fois par
     * exécution de la classe.
     *
     * @var array<string, list<class-string<RetryAfterAware>>>
     */
    private static array $discovered = [];

    public function testEveryQuotaExceptionIsAuditedByItsOwnEventOrJustified(): void
    {
        $quotas = $this->quotaExceptions(self::SOURCES);
        self::assertNotEmpty($quotas, 'Aucune exception de quota recensée : le garde-fou ne garderait rien.');

        $audited = [];
        foreach (array_diff($quotas, array_keys(self::PER_ACCOUNT)) as $class) {
            $audited[$class] = $this->auditMethodsCalledFor($class);
        }

        self::assertSame(
            [],
            $this->violations($audited),
            'Chaque exception de quota doit être triée par ThrottledRequestAuditListener vers un événement `…Throttled` qui n\'appartient qu\'à elle, ou justifiée dans PER_ACCOUNT.',
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
            self::assertSame([], $this->auditMethodsCalledFor($class), $class.' est justifiée comme quota par compte, mais l\'écouteur la trace sur `security_audit`.');
        }
    }

    /**
     * @return iterable<string, array{array<string, list<string>>, array<string, string>}>
     */
    public static function auditCases(): iterable
    {
        yield 'un événement de quota propre à chacun' => [['App\\A' => ['contactThrottled'], 'App\\B' => ['baseAccessThrottled']], []];
        yield 'aucun événement' => [['App\\A' => []], ['App\\A' => 'aucun événement']];
        yield 'deux événements' => [['App\\A' => ['contactThrottled', 'baseAccessThrottled']], ['App\\A' => '2 événements : contactThrottled, baseAccessThrottled']];
        yield 'un événement qui n\'est pas un quota' => [['App\\A' => ['rateLimiterUnavailable']], ['App\\A' => 'rateLimiterUnavailable n\'est pas un événement de quota']];
        yield 'l\'événement de login_throttling' => [['App\\A' => ['loginThrottled']], ['App\\A' => 'loginThrottled n\'est pas un événement de quota']];
        yield 'un événement repris par copier-coller' => [['App\\A' => ['contactThrottled'], 'App\\B' => ['contactThrottled']], ['App\\B' => 'contactThrottled déjà pris par App\\A']];
    }

    /**
     * La règle elle-même, sur des tris synthétiques : sans cette preuve, le
     * premier test pourrait être vert pour de mauvaises raisons.
     *
     * @param array<string, list<string>> $audited
     * @param array<string, string>       $expected
     */
    #[DataProvider('auditCases')]
    public function testTheAuditPolicy(array $audited, array $expected): void
    {
        self::assertSame($expected, $this->violations($audited));
    }

    /**
     * Le recensement réel, sur un répertoire fixture : un quota ajouté sans
     * rattachement doit être vu, puis signalé.
     */
    public function testAQuotaAddedWithoutAnEventIsFlagged(): void
    {
        $quotas = $this->quotaExceptions(self::FIXTURE_SOURCES);

        self::assertSame([UnauditedQuotaFixtureException::class], $quotas);
        self::assertSame(
            [UnauditedQuotaFixtureException::class => 'aucun événement'],
            $this->violations([UnauditedQuotaFixtureException::class => $this->auditMethodsCalledFor(UnauditedQuotaFixtureException::class)]),
        );
    }

    /**
     * @param array<string, list<string>> $audited classe => méthodes du journal appelées par l'écouteur
     *
     * @return array<string, string> classe => écart à la règle
     */
    private function violations(array $audited): array
    {
        $violations = [];
        $owners = [];
        foreach ($audited as $class => $methods) {
            if ([] === $methods) {
                $violations[$class] = 'aucun événement';
                continue;
            }
            if (1 !== \count($methods)) {
                $violations[$class] = \count($methods).' événements : '.implode(', ', $methods);
                continue;
            }
            $method = $methods[0];
            if (!str_ends_with($method, 'Throttled') || isset(self::NOT_QUOTA_EVENTS[$method])) {
                $violations[$class] = $method.' n\'est pas un événement de quota';
                continue;
            }
            if (isset($owners[$method])) {
                $violations[$class] = $method.' déjà pris par '.$owners[$method];
                continue;
            }
            $owners[$method] = $class;
        }

        return $violations;
    }

    /**
     * @return list<class-string<RetryAfterAware>> exceptions de quota concrètes déclarées dans le répertoire
     */
    private function quotaExceptions(string $directory): array
    {
        return self::$discovered[$directory] ??= array_values(array_filter(
            DeclaredClasses::implementing($directory, RetryAfterAware::class),
            static fn (string $class): bool => !(new ReflectionClass($class))->isAbstract(),
        ));
    }

    /**
     * Les méthodes de SecurityAuditLoggerInterface que l'écouteur réel appelle
     * quand l'exception remonte d'une requête principale, dans l'ordre.
     *
     * L'exception est instanciée sans son constructeur : l'écouteur ne trie que
     * sur la classe, et un futur quota n'a aucune raison de partager la
     * signature des quotas existants.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private function auditMethodsCalledFor(string $class): array
    {
        $refusal = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(Throwable::class, $refusal);

        $called = [];
        $auditLogger = self::createStub(SecurityAuditLoggerInterface::class);
        foreach (get_class_methods(SecurityAuditLoggerInterface::class) as $method) {
            $auditLogger->method($method)->willReturnCallback(static function () use (&$called, $method): void {
                $called[] = $method;
            });
        }

        (new ThrottledRequestAuditListener($auditLogger))(new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/test', 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $refusal,
        ));

        return $called;
    }
}
