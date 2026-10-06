<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\InvalidArgumentException as ApiPlatformInvalidArgumentException;
use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Shared\Domain\Exception\HasProblemType;
use App\Tests\Support\CompiledExceptionConfig;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\ExtraConfigKernel;
use App\Tests\Shared\Infrastructure\Http\Fixtures\ExceptionToStatusFixtureResource;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\AttributedFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\InheritingFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\LoggedFixtureProblemException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\UnloggedFixtureProblemException;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
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
 * Le périmètre surveillé est l'union de recensements explicites, sans
 * inférence sur la route qui lève l'exception :
 *
 *  - les ProblemExceptionInterface déclarées dans src/, rendues par
 *    ApiProblemResponseListener sous un contrôleur ou par API Platform ;
 *  - les clés de `api_platform.exception_to_status`, ProblemExceptionInterface
 *    ou non, et celles des `exceptionToStatus` portés par une ressource ou
 *    une opération, que le vendor y fusionne (issue #357) ;
 *  - les exceptions de src/ qui déclarent leur statut par #[WithHttpStatus],
 *    attribut hérité compris (issue #357).
 *
 * Une entrée couvre une classe comme le noyau la résout : `instanceof`, donc
 * aussi par une classe parente ou une interface. S'en dispenser passe par
 * EXEMPT, avec sa justification : c'est la seule échappatoire, et elle se voit.
 *
 * Les deux configurations sont lues **compilées**, dans le conteneur de test
 * (issue #357) : une entrée déclarée dans un autre fichier de config/packages/,
 * dans un bloc `when@test` ou en PHP compte comme le noyau la compte.
 */
final class ExceptionLogLevelCoverageTest extends KernelTestCase
{
    private const string SOURCES = __DIR__.'/../../../../src';
    private const string FIXTURE_SOURCES = __DIR__.'/Fixtures/LogLevelSources';
    private const string CONFIG = __DIR__.'/../../../../config';
    private const string FIXTURE_CONFIG = __DIR__.'/Fixtures/EnvironmentConfig';
    private const string EXTRA_CONFIG = __DIR__.'/Fixtures/ExtraConfig/exceptions.yaml';
    private const string EXTRA_CONFIG_OPTION = 'extra_config';

    /**
     * Les deux mappings que ce garde-fou lit compilés : extension => clé.
     */
    private const array MAPPINGS = ['framework' => 'exceptions', 'api_platform' => 'exception_to_status'];

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
        SerializerExceptionInterface::class => 'Entrée large : couvre aussi l\'encodage de sortie, un défaut serveur — dont les UnsupportedFormatException du journal de test, levées par GET /api (point d\'entrée Hydra en jsonld, coupé en prod). Le corps de requête en est sorti (MalformedRequestBodyException, #355).',
        ApiPlatformInvalidArgumentException::class => 'Entrée large d\'API Platform, sous-classes comprises (ItemNotFoundException, OperationNotFoundException) : levée par la pagination (aucun provider de src/ ne pagine, `?page=0` répond 200), l\'IriConverter (aucune ressource n\'accepte d\'IRI du client) et la lecture des métadonnées, autant de défauts de configuration.',
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

    /**
     * L'option `extra_config` de bootKernel() démarre le noyau avec un fichier
     * de configuration de plus (ExtraConfigKernel). Le noyau est éteint après
     * chaque test (KernelTestCase::tearDown) : les autres tests retrouvent le
     * noyau de l'application.
     *
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $extraConfig = $options[self::EXTRA_CONFIG_OPTION] ?? null;

        return \is_string($extraConfig) ? new ExtraConfigKernel($extraConfig) : parent::createKernel($options);
    }

    public function testEveryExceptionTheApiRendersHasALogLevel(): void
    {
        $rendered = array_values(array_unique([...DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class), ...$this->declaredStatusKeys()]));
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
     * `critical` sur une 404 passeraient sinon le garde-fou. Les statuts sont
     * ceux de `exception_to_status`, des ressources ou opérations — tous, une
     * classe pouvant en recevoir un par opération — et de #[WithHttpStatus], à
     * défaut celui que l'exception déclare (ProblemExceptionInterface::getStatus()).
     */
    public function testEveryLevelMatchesTheStatusItRenders(): void
    {
        $statuses = $this->declaredStatuses();
        foreach (DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class) as $class) {
            $statuses[$class] ??= [(new \ReflectionClass($class))->newInstanceWithoutConstructor()->getStatus() ?? 500];
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
        self::assertSame($allowed ? [] : ['App\\A'], array_keys($this->levelViolations(['App\\A' => [$status]], ['App\\A' => $level])));
    }

    /**
     * @param array<string, list<int>> $statuses classe => statuts rendus
     * @param array<string, string>    $levels   classe => `log_level`
     *
     * @return array<string, string> classe => « statut : niveau » hors politique
     */
    private function levelViolations(array $statuses, array $levels): array
    {
        $violations = [];
        foreach ($statuses as $class => $classStatuses) {
            $level = $levels[$class] ?? null;
            foreach ($classStatuses as $status) {
                $allowed = $status < 500 ? self::CLIENT_ERROR_LEVELS : self::SERVER_ERROR_LEVELS;
                if (null !== $level && !\in_array($level, $allowed, true)) {
                    $violations[$class] = $status.' : '.$level;
                }
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
        $rendered = [...DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class), ...$this->declaredStatusKeys()];
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
     * Un `exceptionToStatus` porté par une ressource ou une opération rend
     * l'exception avec un statut au même titre qu'une clé de
     * `api_platform.exception_to_status` (issue #357). Sur une ressource de
     * test qui en déclare un à chaque niveau, le recensement doit voir les deux,
     * et le garde-fou rougir : aucune n'a de `log_level`.
     */
    public function testTheMappingsOfAResourceAndOfItsOperationsAreWatched(): void
    {
        $statuses = CompiledExceptionConfig::resourceExceptionToStatus(
            self::getContainer()->get('api_platform.metadata.resource.metadata_collection_factory'),
            [ExceptionToStatusFixtureResource::class],
        );
        ksort($statuses);

        self::assertSame([\OverflowException::class => [422], \UnderflowException::class => [409]], $statuses);
        self::assertSame([\OverflowException::class, \UnderflowException::class], $this->uncovered(array_keys($statuses), $this->logLevelKeys()));
    }

    /**
     * Une exception de src/ qui déclare son statut par #[WithHttpStatus] est
     * rendue avec ce statut, mais journalisée au niveau résolu sur l'exception
     * d'origine (issue #357). Sur un répertoire fixture, le recensement doit
     * voir la classe qui porte l'attribut et celle qui en hérite, et le
     * garde-fou rougir sur les deux.
     */
    public function testTheExceptionsThatDeclareAnHttpStatusAreWatched(): void
    {
        $statuses = $this->withHttpStatus(self::FIXTURE_SOURCES);

        self::assertSame([AttributedFixtureException::class => [404], InheritingFixtureException::class => [404]], $statuses);
        self::assertSame(array_keys($statuses), $this->uncovered(array_keys($statuses), $this->logLevelKeys()));
    }

    /**
     * @return array<class-string, list<int>> classe => statut déclaré
     */
    private function withHttpStatus(string $directory): array
    {
        return CompiledExceptionConfig::withHttpStatus(self::getContainer()->get('exception_listener'), DeclaredClasses::all($directory));
    }

    /**
     * La lecture compilée elle-même (issue #357) : une entrée déclarée dans un
     * autre fichier de configuration, qui en surcharge une et en ajoute une
     * autre, doit apparaître dans ce que lit le garde-fou. Le fichier est
     * chargé par un noyau dédié, jamais par la vraie configuration ; une
     * lecture de framework.yaml par Yaml::parseFile ne verrait ni l'une ni
     * l'autre.
     */
    public function testAnEntryDeclaredInAnotherConfigFileIsRead(): void
    {
        self::bootKernel([self::EXTRA_CONFIG_OPTION => self::EXTRA_CONFIG]);
        $levels = $this->logLevelsOf(CompiledExceptionConfig::exceptionsMapping(self::getContainer()->get('exception_listener')));

        self::assertSame('warning', $levels[CpgUserNotFoundException::class] ?? null, 'La surcharge déclarée ailleurs n\'est pas lue.');
        self::assertSame('info', $levels[\DomainException::class] ?? null, 'L\'entrée déclarée ailleurs n\'est pas lue.');
    }

    /**
     * La limite de la lecture compilée : le conteneur de test ne voit que
     * l'environnement `test`. Une entrée déclarée pour `prod` seulement — un
     * bloc `when@prod`, un fichier de config/packages/prod/, un
     * services_prod.yaml — échapperait au garde-fou, et la préproduction comme
     * la production tournent en `prod`. Ces deux configurations restent donc
     * communes à tous les environnements (issue #357).
     */
    public function testNoMappingIsDeclaredForASingleEnvironmentOtherThanTest(): void
    {
        self::assertSame(
            [],
            $this->mappingsOutsideTheTestEnvironment(self::CONFIG),
            'Déclaration propre à un environnement que le conteneur de test ne compile pas : la rendre commune.',
        );
    }

    /**
     * Le recensement lui-même, sur une configuration fixture qui déclare un
     * mapping sous chacune des formes que le noyau charge selon
     * l'environnement (KernelTrait::configureContainer), à côté de déclarations
     * communes ou propres à `test`, qui ne doivent pas ressortir.
     */
    public function testTheEnvironmentScanFindsEveryFormTheKernelLoads(): void
    {
        self::assertSame([
            'packages/framework.yaml : when@prod framework.exceptions',
            'packages/prod/api_platform.yaml : api_platform.exception_to_status',
            'services.yaml : when@dev api_platform.exception_to_status',
            'services_preprod.yaml : framework.exceptions',
        ], $this->mappingsOutsideTheTestEnvironment(self::FIXTURE_CONFIG));
    }

    /**
     * Les trois formes que le noyau charge selon l'environnement
     * (KernelTrait::configureContainer) : un bloc `when@<env>` d'un fichier
     * commun, un fichier de config/packages/<env>/, un services_<env>.yaml.
     * YAML seulement : le projet n'a pas de configuration PHP.
     *
     * @return list<string> « fichier : clé » des déclarations hors `test`
     */
    private function mappingsOutsideTheTestEnvironment(string $configDirectory): array
    {
        $files = [];
        foreach (['/packages/*.yaml', '/packages/*/*.yaml', '/services*.yaml'] as $pattern) {
            $matching = glob($configDirectory.$pattern);
            if (false === $matching) {
                throw new \LogicException(\sprintf('glob() a échoué sur %s%s.', $configDirectory, $pattern));
            }
            array_push($files, ...$matching);
        }

        $found = [];
        foreach ($files as $file) {
            $relative = substr($file, \strlen($configDirectory) + 1);
            $config = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
            if (!\is_array($config)) {
                continue;
            }
            foreach ($this->blocksOutsideTheTestEnvironment($relative, $config) as $prefix => $block) {
                foreach (self::MAPPINGS as $extension => $key) {
                    if (\is_array($block[$extension] ?? null) && \array_key_exists($key, $block[$extension])) {
                        $found[] = $relative.' : '.$prefix.$extension.'.'.$key;
                    }
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @param array<mixed> $config contenu d'un fichier
     *
     * @return array<string, mixed> préfixe du diagnostic => bloc de configuration
     */
    private function blocksOutsideTheTestEnvironment(string $relativePath, array $config): array
    {
        // Un fichier propre à un environnement, en entier.
        if (1 === preg_match('{^packages/([^/]+)/[^/]+\.yaml$}', $relativePath, $matches)
            || 1 === preg_match('{^services_([^/]+)\.yaml$}', $relativePath, $matches)) {
            return 'test' === $matches[1] ? [] : ['' => $config];
        }

        // Un fichier commun : ses blocs `when@<env>`, sauf `when@test`.
        $blocks = [];
        foreach ($config as $key => $block) {
            if (\is_string($key) && str_starts_with($key, 'when@') && 'when@test' !== $key) {
                $blocks[$key.' '] = $block;
            }
        }

        return $blocks;
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
    private function declaredStatusKeys(): array
    {
        return array_keys($this->declaredStatuses());
    }

    /**
     * Les statuts que la configuration ou l'exception elle-même déclarent :
     * `api_platform.exception_to_status`, les `exceptionToStatus` de chaque
     * ressource et de chacune de ses opérations, que le vendor fusionne au
     * moment de rendre l'erreur, et les #[WithHttpStatus] de src/ (issue #357).
     *
     * @return array<string, list<int>> classe => statuts rendus
     */
    private function declaredStatuses(): array
    {
        $container = self::getContainer();
        $statuses = array_map(static fn (int $status): array => [$status], CompiledExceptionConfig::exceptionToStatus($container));
        $byResource = CompiledExceptionConfig::resourceExceptionToStatus(
            $container->get('api_platform.metadata.resource.metadata_collection_factory'),
            $container->get('api_platform.metadata.resource.name_collection_factory')->create(),
        );
        // Une boucle par source, pas un spread : une classe présente dans les
        // deux perdrait les statuts de la première.
        foreach ([$byResource, $this->withHttpStatus(self::SOURCES)] as $source) {
            foreach ($source as $class => $classStatuses) {
                $statuses[$class] = array_values(array_unique([...$statuses[$class] ?? [], ...$classStatuses]));
            }
        }

        return $statuses;
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
        return $this->logLevelsOf(CompiledExceptionConfig::exceptionsMapping(self::getContainer()->get('exception_listener')));
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
