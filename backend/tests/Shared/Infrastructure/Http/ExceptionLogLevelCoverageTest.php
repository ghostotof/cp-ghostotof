<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\InvalidArgumentException as ApiPlatformInvalidArgumentException;
use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\LoggedFixtureProblemException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\UnloggedFixtureProblemException;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Toute exception que l'API rend avec un statut a son `log_level` dans
 * `framework.exceptions` (issue #348).
 *
 * ErrorListener::logKernelException (priorité 0) journalise **toute**
 * exception de la pile HTTP, avant API Platform (-96) comme avant
 * ApiProblemResponseListener (-98) : faute d'entrée, ce qui n'est pas une
 * HttpExceptionInterface sort en `critical`. Une 404 de backoffice, un jeton de
 * mot de passe inconnu ou un quota atteint devenaient ainsi des alertes de
 * production — et un anonyme pouvait en produire à volonté.
 *
 * Le périmètre surveillé est l'union de deux recensements explicites, sans
 * inférence sur la route qui lève l'exception :
 *
 *  - les ProblemExceptionInterface déclarées dans src/, rendues par
 *    ApiProblemResponseListener sous un contrôleur ou par API Platform ;
 *  - les clés de `api_platform.exception_to_status`, ProblemExceptionInterface
 *    ou non.
 *
 * Une entrée couvre une classe comme le noyau la résout : `instanceof`, donc
 * aussi par une classe parente ou une interface. S'en dispenser passe par
 * EXEMPT, avec sa justification : c'est la seule échappatoire, et elle se voit.
 */
final class ExceptionLogLevelCoverageTest extends TestCase
{
    private const string SOURCES = __DIR__.'/../../../../src';
    private const string FIXTURE_SOURCES = __DIR__.'/Fixtures/LogLevelSources';
    private const string FRAMEWORK_CONFIG = __DIR__.'/../../../../config/packages/framework.yaml';
    private const string API_PLATFORM_CONFIG = __DIR__.'/../../../../config/packages/api_platform.yaml';

    /**
     * Entrées larges, rétablies depuis les défauts d'API Platform en fin de
     * `exception_to_status` (audit A15). Leur baisser le niveau abaisserait
     * aussi celui de vrais défauts serveur — un JSON de sortie non encodable,
     * une exception du Serializer sous un contrôleur. Elles restent donc en
     * `critical`, et ce qu'un client peut produire à volonté passe par une
     * classe précise : le corps de requête refusé par le Serializer sort en
     * MalformedRequestBodyException depuis l'issue #355. Les deux autres ne
     * sont atteignables par aucun client aujourd'hui (vérifié le 2026-10-05).
     */
    private const array EXEMPT = [
        SerializerExceptionInterface::class => 'Entrée large : couvre aussi l\'encodage de sortie, un défaut serveur. Le corps de requête en est sorti (MalformedRequestBodyException, #355).',
        ApiPlatformInvalidArgumentException::class => 'Entrée large d\'API Platform : levée par la pagination (aucun provider de src/ ne pagine, `?page=0` répond 200), l\'IriConverter et la lecture des métadonnées, autant de défauts de configuration.',
        OptimisticLockException::class => 'Défaut d\'API Platform ; aucune entité versionnée (pas de #[ORM\Version] dans src/), le conflit serait à observer.',
    ];

    /**
     * Entrées de framework.exceptions qui ne visent ni une ProblemExceptionInterface
     * de src/, ni une clé de `exception_to_status`.
     */
    private const array JUSTIFIED_ENTRIES = [
        UnsupportedMediaTypeHttpException::class => '415 levé par #[MapRequestPayload] et par API Platform sur toute route de l\'API (issue #320) : classe précise, erreur du client.',
    ];

    /**
     * Niveaux admis selon le statut rendu. Un 4xx n'est pas un incident : ni
     * `error` ni `critical`, le bruit que #348 retire, mais pas non plus
     * `debug`, invisible jusqu'en préproduction. Un 5xx reste au moins un
     * `warning`, visible en production (LOG_LEVEL=warning).
     */
    private const array CLIENT_ERROR_LEVELS = ['info', 'notice', 'warning'];
    private const array SERVER_ERROR_LEVELS = ['warning', 'error', 'critical'];

    public function testEveryExceptionTheApiRendersHasALogLevel(): void
    {
        $rendered = array_values(array_unique([...DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class), ...$this->exceptionToStatusKeys()]));
        self::assertNotEmpty($rendered, 'Aucune exception recensée : le garde-fou ne garderait rien.');
        self::assertSame([], array_values(array_diff(array_keys(self::EXEMPT), $rendered)), 'Dispense qui ne correspond plus à rien : la retirer.');

        $watched = array_values(array_diff($rendered, array_keys(self::EXEMPT)));

        self::assertSame(
            [],
            $this->uncovered($watched, $this->logLevelKeys()),
            'Sans `log_level` dans framework.exceptions, ces exceptions sortent en `critical`.',
        );
    }

    /**
     * Le niveau, pas seulement sa présence : un `debug` sur un 503 ou un
     * `critical` sur une 404 passeraient sinon le garde-fou. Le statut est
     * celui de `exception_to_status`, à défaut celui que l'exception déclare
     * (ProblemExceptionInterface::getStatus()).
     */
    public function testEveryLevelMatchesTheStatusItRenders(): void
    {
        $statuses = $this->exceptionToStatus();
        foreach (DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class) as $class) {
            $statuses[$class] ??= (new \ReflectionClass($class))->newInstanceWithoutConstructor()->getStatus() ?? 500;
        }

        self::assertSame([], $this->levelViolations(array_diff_key($statuses, self::EXEMPT), $this->logLevels()));
    }

    /**
     * @return iterable<string, array{int, string, bool}>
     */
    public static function levelCases(): iterable
    {
        yield '404 en info' => [404, 'info', true];
        yield '429 en warning' => [429, 'warning', true];
        yield '404 en debug, invisible' => [404, 'debug', false];
        yield '404 en error, du bruit' => [404, 'error', false];
        yield '404 en critical' => [404, 'critical', false];
        yield '503 en warning' => [503, 'warning', true];
        yield '503 en info, invisible en production' => [503, 'info', false];
        yield '500 en critical' => [500, 'critical', true];
    }

    #[DataProvider('levelCases')]
    public function testTheLevelPolicy(int $status, string $level, bool $allowed): void
    {
        self::assertSame($allowed ? [] : ['App\\A'], array_keys($this->levelViolations(['App\\A' => $status], ['App\\A' => $level])));
    }

    /**
     * @param array<string, int>    $statuses classe => statut rendu
     * @param array<string, string> $levels   classe => `log_level`
     *
     * @return array<string, string> classe => « statut : niveau » hors politique
     */
    private function levelViolations(array $statuses, array $levels): array
    {
        $violations = [];
        foreach ($statuses as $class => $status) {
            $level = $levels[$class] ?? null;
            $allowed = $status < 500 ? self::CLIENT_ERROR_LEVELS : self::SERVER_ERROR_LEVELS;
            if (null !== $level && !\in_array($level, $allowed, true)) {
                $violations[$class] = $status.' : '.$level;
            }
        }

        return $violations;
    }

    /**
     * L'inverse du test précédent : chaque entrée vise une exception recensée.
     * Une entrée large (`\DomainException`, une interface…) couvrirait tout en
     * apparence, abaisserait aussi de vrais défauts serveur, et masquerait les
     * entrées précises placées après elle — le noyau retient la première qui
     * correspond. Interdites, donc, sauf justification.
     */
    public function testEveryLogLevelEntryTargetsARenderedException(): void
    {
        $rendered = [...DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class), ...$this->exceptionToStatusKeys()];
        self::assertSame([], array_values(array_diff(array_keys(self::JUSTIFIED_ENTRIES), $this->logLevelKeys())), 'Justification d\'une entrée qui n\'existe plus : la retirer.');

        self::assertSame(
            [],
            array_values(array_diff($this->logLevelKeys(), $rendered, array_keys(self::JUSTIFIED_ENTRIES))),
            'Entrée de framework.exceptions qui ne vise aucune exception recensée : la viser précisément, ou la justifier.',
        );
    }

    /**
     * Une dispense ne doit pas cacher une entrée : sinon elle ment, et la
     * justification n'a plus d'objet.
     */
    public function testNoExemptedExceptionHasALogLevel(): void
    {
        self::assertSame([], array_values(array_intersect(array_keys(self::EXEMPT), $this->logLevelKeys())));
    }

    /**
     * @return iterable<string, array{class-string, list<string>, bool}>
     */
    public static function detectorCases(): iterable
    {
        $unlogged = (new class('Vide.') extends \DomainException implements ProblemExceptionInterface {
            use HasProblemType;

            protected function problemType(): string
            {
                return 'unlogged';
            }

            protected function problemStatus(): int
            {
                return 409;
            }
        })::class;

        // RuntimeException, pas LogicException : DomainException en hérite.
        yield 'exception de test sans entrée' => [$unlogged, [\RuntimeException::class], false];
        yield 'aucune entrée du tout' => [$unlogged, [], false];
        yield 'entrée sur la classe elle-même' => [$unlogged, [$unlogged], true];
        yield 'entrée sur une classe parente' => [$unlogged, [\DomainException::class], true];
        yield 'entrée sur une interface' => [$unlogged, [ProblemExceptionInterface::class], true];
    }

    /**
     * Le détecteur lui-même, sur une exception de test : sans cette preuve, le
     * garde-fou pourrait être vert pour de mauvaises raisons.
     *
     * @param class-string $class
     * @param list<string> $logLevelKeys
     */
    #[DataProvider('detectorCases')]
    public function testTheDetector(string $class, array $logLevelKeys, bool $covered): void
    {
        self::assertSame($covered ? [] : [$class], $this->uncovered([$class], $logLevelKeys));
    }

    /**
     * Le recensement réel, sur un répertoire fixture rejouable : un fichier qui
     * déclare deux exceptions sans porter le nom d'aucune. La découverte doit
     * voir les deux, et le garde-fou rougir sur celle qui n'a pas d'entrée.
     */
    public function testTheDiscoveryFindsEveryDeclaredProblemAndFlagsTheUnlogged(): void
    {
        $found = DeclaredClasses::implementing(self::FIXTURE_SOURCES, ProblemExceptionInterface::class);

        self::assertSame([LoggedFixtureProblemException::class, UnloggedFixtureProblemException::class], $found);
        self::assertSame([UnloggedFixtureProblemException::class], $this->uncovered($found, [LoggedFixtureProblemException::class]));
    }

    /**
     * Une entrée couvre une classe comme le noyau la résout : par `instanceof`
     * (ErrorListener::resolveLogLevel). L'ordre des entrées, qui départage
     * pour le noyau, n'entre pas en jeu ici : testEveryLogLevelEntryTargetsARenderedException
     * n'admet que des entrées précises, qui ne se recouvrent donc pas.
     *
     * @param list<string> $classes
     * @param list<string> $logLevelKeys
     *
     * @return list<string> classes qu'aucune entrée ne couvre
     */
    private function uncovered(array $classes, array $logLevelKeys): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => !array_any($logLevelKeys, static fn (string $key): bool => is_a($class, $key, true)),
        ));
    }

    /**
     * @return list<string>
     */
    private function exceptionToStatusKeys(): array
    {
        return array_keys($this->exceptionToStatus());
    }

    /**
     * @return array<string, int>
     */
    private function exceptionToStatus(): array
    {
        /** @var array{api_platform: array{exception_to_status?: array<string, int>}} $config */
        $config = Yaml::parseFile(self::API_PLATFORM_CONFIG);

        return $config['api_platform']['exception_to_status'] ?? [];
    }

    /**
     * @return list<string>
     */
    private function logLevelKeys(): array
    {
        return array_keys($this->logLevels());
    }

    /**
     * @return array<string, string>
     */
    private function logLevels(): array
    {
        /** @var array{framework: array{exceptions?: array<string, array<string, mixed>>}} $config */
        $config = Yaml::parseFile(self::FRAMEWORK_CONFIG);

        return $this->logLevelsOf($config['framework']['exceptions'] ?? []);
    }

    /**
     * Seules comptent les entrées qui fixent un `log_level` : une entrée qui
     * ne porte qu'un `status_code`, ou un `log_level: ~`, laisse le niveau au
     * noyau — donc `critical`.
     *
     * @param array<string, array<string, mixed>> $exceptions `framework.exceptions`
     *
     * @return array<string, string> classe => niveau
     */
    private function logLevelsOf(array $exceptions): array
    {
        $levels = [];
        foreach ($exceptions as $class => $options) {
            $level = $options['log_level'] ?? null;
            if (\is_string($level) && '' !== $level) {
                $levels[$class] = $level;
            }
        }

        return $levels;
    }

    /**
     * @return iterable<string, array{array<string, array<string, mixed>>, array<string, string>}>
     */
    public static function entries(): iterable
    {
        yield 'log_level fixé' => [['App\\A' => ['log_level' => 'info']], ['App\\A' => 'info']];
        yield 'status_code seul' => [['App\\A' => ['status_code' => 404]], []];
        yield 'log_level nul' => [['App\\A' => ['log_level' => null]], []];
        yield 'log_level vide' => [['App\\A' => ['log_level' => '']], []];
    }

    /**
     * @param array<string, array<string, mixed>> $exceptions
     * @param array<string, string>               $expected
     */
    #[DataProvider('entries')]
    public function testOnlyEntriesThatSetALevelCount(array $exceptions, array $expected): void
    {
        self::assertSame($expected, $this->logLevelsOf($exceptions));
    }
}
