<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\InvalidArgumentException as ApiPlatformInvalidArgumentException;
use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
    private const string FRAMEWORK_CONFIG = __DIR__.'/../../../../config/packages/framework.yaml';
    private const string API_PLATFORM_CONFIG = __DIR__.'/../../../../config/packages/api_platform.yaml';

    /**
     * Entrées larges, rétablies depuis les défauts d'API Platform en fin de
     * `exception_to_status` (audit A15). Leur baisser le niveau abaisserait
     * aussi celui de vrais défauts serveur — un JSON de sortie non encodable,
     * une exception du Serializer sous un contrôleur — : le côté entrée (JSON
     * illisible envoyé par le client) se traite à part, par une classe précise
     * (issue #355).
     */
    private const array EXEMPT = [
        SerializerExceptionInterface::class => 'Entrée large : couvre aussi l\'encodage de sortie, un défaut serveur.',
        ApiPlatformInvalidArgumentException::class => 'Entrée large d\'API Platform, levée aussi sur un défaut de configuration.',
        OptimisticLockException::class => 'Défaut d\'API Platform ; aucune entité versionnée aujourd\'hui, le conflit serait à observer.',
    ];

    public function testEveryExceptionTheApiRendersHasALogLevel(): void
    {
        $rendered = array_values(array_unique([...$this->problemClassesInSources(), ...$this->exceptionToStatusKeys()]));
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
    public static function coverage(): iterable
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
    #[DataProvider('coverage')]
    public function testTheDetector(string $class, array $logLevelKeys, bool $covered): void
    {
        self::assertSame($covered ? [] : [$class], $this->uncovered([$class], $logLevelKeys));
    }

    /**
     * Même résolution que ErrorListener::resolveLogLevel : la première entrée
     * dont la classe est un `instanceof` l'emporte.
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
     * @return list<string> FQCN des ProblemExceptionInterface de src/ (PSR-4 : App\ => src/)
     */
    private function problemClassesInSources(): array
    {
        $root = (string) realpath(self::SOURCES);
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($root) + 1, -\strlen('.php'));
            $class = 'App\\'.str_replace('/', '\\', $relative);
            if (class_exists($class) && is_subclass_of($class, ProblemExceptionInterface::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function exceptionToStatusKeys(): array
    {
        /** @var array{api_platform: array{exception_to_status?: array<string, int>}} $config */
        $config = Yaml::parseFile(self::API_PLATFORM_CONFIG);

        return array_keys($config['api_platform']['exception_to_status'] ?? []);
    }

    /**
     * Seules comptent les entrées qui fixent un `log_level` : une entrée qui
     * ne porterait qu'un `status_code` laisse le niveau au noyau.
     *
     * @return list<string>
     */
    private function logLevelKeys(): array
    {
        /** @var array{framework: array{exceptions?: array<string, array{log_level?: ?string}>}} $config */
        $config = Yaml::parseFile(self::FRAMEWORK_CONFIG);

        return array_keys(array_filter(
            $config['framework']['exceptions'] ?? [],
            static fn (array $options): bool => null !== ($options['log_level'] ?? null) && '' !== $options['log_level'],
        ));
    }
}
