<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use ApiPlatform\Metadata\Exception\InvalidArgumentException as ApiPlatformInvalidArgumentException;
use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Contact\Presentation\ApiResource\ContactMessageResource;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Shared\Domain\Exception\HasProblemType;
use App\Tests\Support\CompiledExceptionConfig;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\ExtraConfigKernel;
use App\Tests\Shared\Infrastructure\Http\Fixtures\ExceptionToStatusFixtureResource;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes\AbstractAttributedFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes\AttributeLoggedFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelAttributes\LoudAttributeFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\AttributedFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\InheritingFixtureException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\LoggedFixtureProblemException;
use App\Tests\Shared\Infrastructure\Http\Fixtures\LogLevelSources\UnloggedFixtureProblemException;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;

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
    private const string EXTRA_CONFIG = __DIR__.'/Fixtures/ExtraConfig/exceptions.yaml';
    private const string FIXTURE_ATTRIBUTES = __DIR__.'/Fixtures/LogLevelAttributes';
    private const string EXTRA_CONFIG_OPTION = 'extra_config';

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
        $rendered = array_keys($this->renderedStatuses());
        self::assertNotEmpty($rendered, 'Aucune exception recensée : le garde-fou ne garderait rien.');
        self::assertSame([], array_values(array_diff(array_keys(self::EXEMPT), $rendered)), 'Dispense qui ne correspond plus à rien : la retirer.');

        $watched = array_values(array_diff($rendered, array_keys(self::EXEMPT)));

        self::assertSame(
            [],
            $this->uncovered($watched, $this->logLevelKeys(), $this->withLogLevel($watched)),
            'Sans `log_level` dans framework.exceptions, ces exceptions sortent en `critical`.',
        );
    }

    /**
     * Le niveau, pas seulement sa présence : un `debug` sur un 503 ou un
     * `critical` sur une 404 passeraient sinon le garde-fou. Il est jugé contre
     * chaque statut que l'exception peut recevoir (renderedStatuses), au niveau
     * que le noyau retient pour elle.
     */
    public function testEveryLevelMatchesTheStatusItRenders(): void
    {
        $watched = array_diff_key($this->renderedStatuses(), self::EXEMPT);

        self::assertSame([], $this->levelViolations($watched, $this->kernelLevels(self::getContainer()->get('exception_listener'), array_keys($watched))));
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
        $rendered = array_keys($this->renderedStatuses());
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
        $mappings = CompiledExceptionConfig::apiPlatformMappings(
            self::getContainer()->get('api_platform.metadata.resource.metadata_collection_factory'),
            [ExceptionToStatusFixtureResource::class],
            [],
        );
        $statuses = [];
        foreach ([\OverflowException::class, \UnderflowException::class] as $class) {
            $statuses[$class] = CompiledExceptionConfig::statusesFor($mappings, $class);
        }

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
        $statuses = $this->kernelConversions(self::FIXTURE_SOURCES);

        self::assertSame([AttributedFixtureException::class => [404], InheritingFixtureException::class => [404]], $statuses);
        self::assertSame(array_keys($statuses), $this->uncovered(array_keys($statuses), $this->logLevelKeys()));
    }

    /**
     * Les classes d'un répertoire que le noyau de l'application convertit, et
     * leur statut (CompiledExceptionConfig::kernelHttpStatus).
     *
     * @return array<string, list<int>> classe => statut rendu
     */
    private function kernelConversions(string $directory): array
    {
        return array_map(
            static fn (int $status): array => [$status],
            CompiledExceptionConfig::kernelHttpStatus(self::getContainer()->get('exception_listener'), DeclaredClasses::all($directory)),
        );
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
     * Le recensement ne lit que les opérations HTTP : celles de GraphQL ne
     * portent pas d'`exceptionToStatus`, et leurs erreurs suivent un autre
     * chemin. GraphQL est désactivé (webonyx/graphql-php n'est pas installé),
     * et la fabrique de métadonnées refuse alors toute opération GraphQL : le
     * cas n'existe pas aujourd'hui. Ce témoin rougit le jour où il existe
     * (issue #357).
     */
    public function testGraphQlStaysDisabled(): void
    {
        self::assertFalse(
            self::getContainer()->getParameter('api_platform.graphql.enabled'),
            'GraphQL activé : ses erreurs échappent à ce garde-fou. Étendre le recensement à ses opérations avant de retirer ce témoin.',
        );
    }

    /**
     * Une entrée `framework.exceptions` qui fixe un `status_code` fait
     * convertir l'exception par le noyau — rendue avec ce statut —, mais son
     * niveau reste résolu sur l'exception d'origine : sans `log_level`,
     * `critical` (issue #357).
     */
    public function testAStatusCodeEntryConvertsTheException(): void
    {
        $listener = $this->listenerWithStatusCodes([\OverflowException::class => 404]);

        self::assertSame([\OverflowException::class => 404], CompiledExceptionConfig::kernelHttpStatus($listener, [\OverflowException::class]));
    }

    /**
     * L'ordre du noyau : une entrée `status_code` convertit l'exception avant
     * que #[WithHttpStatus] soit lu ; c'est son statut qui est rendu.
     */
    public function testAStatusCodeEntryTakesPrecedenceOverWithHttpStatus(): void
    {
        $listener = $this->listenerWithStatusCodes([\DomainException::class => 503]);

        self::assertSame(
            [AttributeLoggedFixtureException::class => 503],
            CompiledExceptionConfig::kernelHttpStatus($listener, [AttributeLoggedFixtureException::class]),
        );
    }

    /**
     * Une exception abstraite n'est jamais levée telle quelle.
     */
    public function testAnAbstractExceptionIsNeverConverted(): void
    {
        self::assertSame([], CompiledExceptionConfig::kernelHttpStatus(self::getContainer()->get('exception_listener'), [AbstractAttributedFixtureException::class]));
    }

    /**
     * Dans une table `exception_to_status`, API Platform retient la première
     * clé qui correspond par is_a(), pas l'entrée au nom exact de la classe
     * (ErrorListener::getStatusCode).
     */
    public function testTheFirstMatchingKeyGivesTheStatus(): void
    {
        self::assertSame(
            [500],
            CompiledExceptionConfig::statusesFor([[\RuntimeException::class => 500, \OverflowException::class => 404]], \OverflowException::class),
        );
    }

    /**
     * Les tables qu'API Platform consulte : le paramètre global seul, et fusionné
     * avec celle de l'opération (array_merge : une clé déjà présente garde sa
     * place et prend la valeur de l'opération). La même exception peut donc
     * être rendue avec l'un ou l'autre statut.
     */
    public function testAnOperationOverridesTheGlobalStatusInPlace(): void
    {
        $mappings = CompiledExceptionConfig::apiPlatformMappings(
            self::getContainer()->get('api_platform.metadata.resource.metadata_collection_factory'),
            [ExceptionToStatusFixtureResource::class],
            [\OverflowException::class => 404],
        );

        self::assertSame([404, 422], CompiledExceptionConfig::statusesFor($mappings, \OverflowException::class));
    }

    /**
     * Le noyau lit #[WithLogLevel] à défaut d'entrée (ErrorListener::resolveLogLevel) :
     * une exception qui le porte, ou en hérite, a un niveau (issue #357). Le
     * projet préfère `framework.exceptions`, pour que le domaine ne dépende pas
     * de HttpKernel ; ce n'est pas à ce garde-fou de le dire en se trompant.
     */
    public function testAWithLogLevelAttributeCoversItsException(): void
    {
        $classes = array_keys($this->kernelConversions(self::FIXTURE_ATTRIBUTES));

        self::assertCount(3, $classes);
        self::assertSame([], $this->uncovered($classes, $this->logLevelKeys(), $this->withLogLevel($classes)));
    }

    /**
     * Le niveau qu'il fixe obéit à la même politique qu'une entrée.
     */
    public function testAWithLogLevelAttributeIsHeldToTheLevelPolicy(): void
    {
        $statuses = $this->kernelConversions(self::FIXTURE_ATTRIBUTES);

        self::assertSame([LoudAttributeFixtureException::class => '404 : critical'], $this->levelViolations($statuses, $this->kernelLevels(self::getContainer()->get('exception_listener'), array_keys($statuses))));
    }

    /**
     * L'ordre du noyau, sur lequel le contrôle des niveaux s'appuie : une
     * entrée qui correspond l'emporte sur l'attribut.
     */
    public function testAnEntryTakesPrecedenceOverTheAttribute(): void
    {
        $listener = $this->listenerWith([\DomainException::class => 'info']);

        self::assertSame([LoudAttributeFixtureException::class => 'info'], $this->kernelLevels($listener, [LoudAttributeFixtureException::class]));
    }

    /**
     * Le niveau contrôlé est celui que le noyau retient, pas seulement celui
     * d'une entrée au nom exact de la classe : une 404 couverte par l'entrée
     * de sa classe parente, en `critical`, est hors politique (issue #357).
     */
    public function testTheLevelOfAParentEntryIsHeldToThePolicy(): void
    {
        $levels = $this->kernelLevels($this->listenerWith([\RuntimeException::class => 'critical']), [\OverflowException::class]);

        self::assertSame([\OverflowException::class => '404 : critical'], $this->levelViolations([\OverflowException::class => [404]], $levels));
    }

    /**
     * Le niveau que le noyau retient pour chaque classe (CompiledExceptionConfig::kernelLogLevel).
     *
     * @param list<string> $classes
     *
     * @return array<string, string> classe => niveau ; une classe abstraite en est absente
     */
    private function kernelLevels(ErrorListener $listener, array $classes): array
    {
        $levels = [];
        foreach ($classes as $class) {
            if (is_subclass_of($class, \Throwable::class)) {
                $level = CompiledExceptionConfig::kernelLogLevel($listener, $class);
                if (null !== $level) {
                    $levels[$class] = $level;
                }
            }
        }

        return $levels;
    }

    /**
     * Un ErrorListener du noyau avec ces seules entrées `status_code`.
     *
     * @param array<class-string, int<100, 599>> $statuses classe => `status_code`, dans l'ordre
     */
    private function listenerWithStatusCodes(array $statuses): ErrorListener
    {
        return new ErrorListener(null, null, false, array_map(
            static fn (int $status): array => ['log_level' => null, 'status_code' => $status, 'log_channel' => null],
            $statuses,
        ));
    }

    /**
     * Un ErrorListener du noyau avec ces seules entrées de `framework.exceptions`.
     *
     * @param array<class-string, string> $levels classe => `log_level`, dans l'ordre
     */
    private function listenerWith(array $levels): ErrorListener
    {
        return new ErrorListener(null, null, false, array_map(
            static fn (string $level): array => ['log_level' => $level, 'status_code' => null, 'log_channel' => null],
            $levels,
        ));
    }

    /**
     * @param list<string> $classes
     *
     * @return array<string, string> classe => niveau de son #[WithLogLevel]
     */
    private function withLogLevel(array $classes): array
    {
        return CompiledExceptionConfig::withLogLevel(self::getContainer()->get('exception_listener'), $classes);
    }

    /**
     * Une entrée couvre une classe comme le noyau la résout : par `instanceof`
     * (ErrorListener::resolveLogLevel), donc aussi par une classe parente ou
     * une interface ; à défaut, un #[WithLogLevel] hérité. Seule la présence
     * compte ici. L'ordre des entrées, lui, compte pour le niveau retenu — deux
     * entrées peuvent se recouvrir, une exception #[WithHttpStatus] et sa
     * sous-classe étant toutes deux recensées : le contrôle des niveaux le lit
     * donc au noyau lui-même (kernelLevels), jamais ici.
     *
     * @param list<string>          $classes
     * @param list<string>          $logLevelKeys
     * @param array<string, string> $attributeLevels classe => niveau de son #[WithLogLevel]
     *
     * @return list<string> classes qu'aucune entrée ni aucun attribut ne couvre
     */
    private function uncovered(array $classes, array $logLevelKeys, array $attributeLevels = []): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $class): bool => !isset($attributeLevels[$class])
                && !array_any($logLevelKeys, static fn (string $key): bool => is_a($class, $key, true)),
        ));
    }

    /**
     * Chaque exception que l'API rend avec un statut, et tous les statuts
     * qu'elle peut recevoir — un sur-ensemble prudent : le niveau doit convenir
     * à chacun. Sans inférence sur la route qui lève l'exception :
     *
     *  - API Platform : chaque clé de ses tables (le paramètre global, les
     *    `exceptionToStatus` de ressource et d'opération), avec dans chaque
     *    table le statut de la première clé qui lui correspond par is_a() ;
     *  - les ProblemExceptionInterface concrètes de src/ : celui que porte
     *    getStatus(), rendu par ApiProblemResponseListener sous un contrôleur ;
     *  - le noyau : les exceptions qu'il convertit lui-même, par une entrée
     *    `status_code` ou un #[WithHttpStatus] (issue #357).
     *
     * @return array<string, list<int>> classe => statuts
     */
    private function renderedStatuses(): array
    {
        $container = self::getContainer();
        $listener = $container->get('exception_listener');
        /** @var array<string, int> $global */
        $global = $container->getParameter('api_platform.exception_to_status');
        $resourceClasses = iterator_to_array($container->get('api_platform.metadata.resource.name_collection_factory')->create(), false);
        // Une découverte qui ne trouverait rien laisserait les garde-fous verts
        // pour rien : une ressource connue doit y figurer.
        self::assertContains(ContactMessageResource::class, $resourceClasses, 'La découverte des ressources API Platform ne trouve plus ContactMessageResource : les exceptionToStatus des opérations ne sont plus lus.');
        $mappings = CompiledExceptionConfig::apiPlatformMappings(
            $container->get('api_platform.metadata.resource.metadata_collection_factory'),
            $resourceClasses,
            $global,
        );
        $problems = array_values(array_filter(
            DeclaredClasses::implementing(self::SOURCES, ProblemExceptionInterface::class),
            static fn (string $class): bool => !(new \ReflectionClass($class))->isAbstract(),
        ));
        $converted = CompiledExceptionConfig::kernelHttpStatus($listener, [...DeclaredClasses::all(self::SOURCES), ...$this->statusCodeKeys()]);

        $classes = array_unique([...array_merge(...array_map(array_keys(...), $mappings)), ...$problems, ...array_keys($converted)]);
        $statuses = [];
        foreach ($classes as $class) {
            $classStatuses = CompiledExceptionConfig::statusesFor($mappings, $class);
            if (\in_array($class, $problems, true)) {
                $classStatuses[] = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->getStatus() ?? 500;
            }
            if (isset($converted[$class])) {
                $classStatuses[] = $converted[$class];
            }
            $statuses[$class] = array_values(array_unique($classStatuses));
        }

        return $statuses;
    }

    /**
     * Les entrées de `framework.exceptions` qui fixent un `status_code`.
     *
     * @return list<string>
     */
    private function statusCodeKeys(): array
    {
        $mapping = CompiledExceptionConfig::exceptionsMapping(self::getContainer()->get('exception_listener'));

        return array_keys(array_filter($mapping, static fn (array $options): bool => null !== $options['status_code']));
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
