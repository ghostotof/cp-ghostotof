<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use App\Tests\Support\ApiPlatformDeprecations;
use App\Tests\Support\RepositoryFiles;
use Closure;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Le collecteur de dépréciations d'API Platform (issue #701), dont se sert
 * {@see \App\Tests\Shared\Infrastructure\ApiPlatform\ApiPlatformRaisesNoDeprecationTest} : ce qu'il retient, ce qu'il laisse
 * passer, et qu'il rend l'état qu'il a trouvé.
 *
 * Il ne vaut que s'il ne ment dans aucun sens : une dépréciation d'API
 * Platform non vue rend la garde aveugle (c'est la raison d'être de ce
 * ticket, `ignoreIndirectDeprecations` de phpunit.dist.xml l'étant déjà), et
 * une erreur d'un autre paquet avalée ferait disparaître un vrai signal.
 */
final class ApiPlatformDeprecationCollectorTest extends TestCase
{
    private ?string $fixtureDirectory = null;

    /**
     * Une pile, de la plus proche (le gestionnaire d'erreurs, appelé depuis
     * `trigger_error()` de `deprecation-contracts`) à la plus lointaine : le
     * `file` d'une trame est l'endroit d'où elle a été appelée.
     *
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function messagesAndStacks(): iterable
    {
        $trigger = '/var/www/vendor/symfony/deprecation-contracts/function.php';

        yield 'message d\'API Platform, appelant dans le projet' => [
            'Since api-platform/core 4.4: Calling "denormalize()" is deprecated.',
            [$trigger, '/var/www/vendor/api-platform/core/Foo.php', RepositoryFiles::backend('src/Contact/Foo.php')],
            true,
        ];
        yield 'message d\'un composant d\'API Platform' => [
            'Since api-platform/serializer 4.4: x',
            [$trigger, RepositoryFiles::backend('src/Contact/Foo.php')],
            true,
        ];
        yield 'autre paquet, appelé par API Platform avant tout fichier du projet' => [
            'Since symfony/serializer 8.1: x',
            [
                $trigger,
                '/var/www/vendor/symfony/serializer/Normalizer.php',
                '/var/www/vendor/api-platform/serializer/ItemNormalizer.php',
                '/var/www/vendor/symfony/http-kernel/HttpKernel.php',
                RepositoryFiles::backend('src/Contact/Foo.php'),
            ],
            true,
        ];
        yield 'message sans paquet, appelé par API Platform' => [
            'Un message libre',
            [$trigger, '/var/www/vendor/api-platform/state/Foo.php'],
            true,
        ];
        yield 'autre paquet, appelé par le projet lui-même' => [
            'Since symfony/serializer 8.1: x',
            [
                $trigger,
                '/var/www/vendor/symfony/serializer/Normalizer.php',
                RepositoryFiles::backend('src/Contact/Foo.php'),
                '/var/www/vendor/api-platform/state/Processor.php',
            ],
            false,
        ];
        yield 'autre paquet, appelé depuis les tests avant API Platform' => [
            'Since symfony/serializer 8.1: x',
            [$trigger, '/var/www/vendor/symfony/serializer/Normalizer.php', RepositoryFiles::backend('tests/Foo/FooTest.php'), '/var/www/vendor/api-platform/state/Processor.php'],
            false,
        ];
        yield 'autre paquet, jamais appelé par API Platform' => [
            'Since doctrine/orm 3.6: x',
            [$trigger, '/var/www/vendor/doctrine/orm/src/Foo.php', '/var/www/vendor/symfony/http-kernel/HttpKernel.php'],
            false,
        ];
        yield 'un `src/` de vendor n\'est pas le projet' => [
            'Since symfony/serializer 8.1: x',
            [$trigger, '/var/www/vendor/symfony/serializer/src/Normalizer.php', '/var/www/vendor/api-platform/state/Foo.php'],
            true,
        ];
        // Faux positif assumé : la sous-requête de l'ErrorListener d'API
        // Platform passe par le noyau de Symfony, qui appelle une API
        // dépréciée de Symfony, et API Platform n'a rien à migrer. Mais « API
        // Platform est dans la pile avant le projet » est tout ce que la garde
        // sait dire sans deviner où un paquet intermédiaire s'arrête : un
        // faux positif est bruyant et se corrige d'une ligne, un faux négatif
        // est silencieux (le critère du premier appelant extérieur, essayé
        // puis retiré, laissait passer un proxy ou un autoloader en travers).
        yield 'faux positif assumé : API Platform appelle le noyau, qui appelle une API dépréciée de Symfony' => [
            'Since symfony/bar 8.1: x',
            [
                $trigger,
                '/var/www/vendor/symfony/bar/Legacy.php',
                '/var/www/vendor/symfony/kernel/Handle.php',
                '/var/www/vendor/api-platform/x/Caller.php',
                RepositoryFiles::backend('src/Contact/Foo.php'),
            ],
            true,
        ];
        // D1 : une dépréciation levée au chargement d'une classe (au niveau du
        // fichier) a l'autoloader de Composer pour premier appelant.
        yield 'D1 : levée au chargement d\'une classe, par un autoloader appelé depuis API Platform' => [
            'Since symfony/serializer 8.1: The class is deprecated.',
            [
                $trigger,
                '/var/www/vendor/symfony/serializer/Legacy.php',
                '/var/www/vendor/composer/ClassLoader.php',
                '/var/www/vendor/composer/ClassLoader.php',
                '/var/www/vendor/api-platform/serializer/Factory.php',
                RepositoryFiles::backend('src/Contact/Foo.php'),
            ],
            true,
        ];
        // D2 : une couche d'un autre paquet entre API Platform et la
        // dépréciation (un proxy paresseux, un décorateur) ne la masque pas.
        yield 'D2 : un proxy de var-exporter entre API Platform et la dépréciation' => [
            'Since symfony/serializer 8.1: x',
            [
                $trigger,
                '/var/www/vendor/symfony/serializer/Normalizer.php',
                '/var/www/vendor/symfony/var-exporter/LazyObjectRegistry.php',
                '/var/www/vendor/api-platform/serializer/ItemNormalizer.php',
                RepositoryFiles::backend('src/Contact/Foo.php'),
            ],
            true,
        ];
        // D7 : un fichier hors du projet et hors d'API Platform n'est « le
        // paquet » de personne, même sous un dossier nommé `vendor` (un clone
        // placé sous `/…/vendor/clone/`) : le conteneur compilé est une colle.
        yield 'D7 : le conteneur compilé d\'un clone sous un dossier vendor/ n\'est pas un paquet' => [
            'Since symfony/serializer 8.1: x',
            [
                $trigger,
                '/var/www/vendor/symfony/serializer/Normalizer.php',
                '/home/x/vendor/clone/backend/var/cache/test/ContainerAbc/getFooService.php',
                '/var/www/vendor/api-platform/state/Processor.php',
            ],
            true,
        ];
        yield 'le paquet qui lève s\'appelle lui-même avant API Platform' => [
            'Since symfony/serializer 8.1: x',
            [
                $trigger,
                '/var/www/vendor/symfony/serializer/Legacy.php',
                '/var/www/vendor/symfony/serializer/Normalizer.php',
                '/var/www/vendor/api-platform/x/Caller.php',
            ],
            true,
        ];
        // C4 : un `/src/` ou un `/tests/` en sous-chaîne d'un chemin n'est pas le projet.
        yield 'un clone sous un dossier src/ : le cache du conteneur n\'est pas le projet' => [
            'Since symfony/serializer 8.1: x',
            [
                $trigger,
                '/var/www/vendor/symfony/serializer/Normalizer.php',
                '/home/x/src/clone/backend/var/cache/test/ContainerAbc/getFooService.php',
                '/var/www/vendor/api-platform/state/Processor.php',
            ],
            true,
        ];
        yield 'un dossier qui ressemble sans l\'être' => [
            'Since symfony/serializer 8.1: x',
            [$trigger, '/var/www/vendor/api-platform-bridge/Foo.php'],
            false,
        ];
        yield 'le préfixe doit commencer le message' => [
            'Voir Since api-platform/core 4.4: x',
            [$trigger, RepositoryFiles::backend('src/Contact/Foo.php')],
            false,
        ];
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('messagesAndStacks')]
    public function testItJudgesAnApiPlatformDeprecationByItsMessageOrItsStack(string $message, array $files, bool $expected): void
    {
        $frames = array_map(static fn (string $file): array => ['file' => $file, 'line' => 1], $files);

        self::assertSame($expected, ApiPlatformDeprecations::concerns($message, $frames));
    }

    /**
     * Le cas que la garde existe pour voir : API Platform appelle une API
     * dépréciée d'un autre paquet. Une vraie chaîne d'appels, dont les fichiers
     * portent les chemins de vendor (ils sont écrits sous un dossier
     * temporaire : un dossier `vendor/` ne se versionne pas ici).
     */
    public function testItReportsAnotherPackagesDeprecationRaisedOnBehalfOfApiPlatform(): void
    {
        $findings = ApiPlatformDeprecations::during($this->callThroughApiPlatform());

        self::assertCount(1, $findings, 'Une dépréciation de Symfony appelée par API Platform doit être relevée.');
        self::assertStringContainsString('Since symfony/foo 8.1: Calling "legacy()" is deprecated.', $findings[0]);
        // Elle cite la méthode dépréciée et le fichier d'API Platform qui l'appelle.
        self::assertStringContainsString('LegacyApi::legacy()', $findings[0]);
        self::assertStringContainsString('/vendor/api-platform/core/Caller.php', $findings[0]);
    }

    /**
     * La même API dépréciée, appelée par le projet : c'est l'affaire du projet,
     * que PHPUnit compte ; le collecteur la laisse au gestionnaire en place.
     */
    public function testItLeavesTheSameDeprecationAloneWhenTheProjectCallsTheDeprecatedApi(): void
    {
        $received = [];
        set_error_handler(static function (int $level, string $message) use (&$received): bool {
            $received[] = $message;

            return true;
        });

        try {
            $findings = ApiPlatformDeprecations::during($this->callFromTheProject());
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $findings);
        self::assertSame(['Since symfony/foo 8.1: Calling "legacy()" is deprecated.'], $received);
    }

    /**
     * D1, sur de vrais fichiers : une dépréciation levée au niveau du fichier,
     * au chargement d'une classe, a pour premier appelant l'autoloader de
     * Composer (`vendor/composer/`), pas API Platform qui a demandé la classe.
     * Elle reste la sienne : la pile traverse un fichier d'API Platform avant
     * le premier fichier du projet.
     */
    public function testItReportsADeprecationRaisedWhenAClassFileIsLoadedOnBehalfOfApiPlatform(): void
    {
        $caller = $this->writeFileLevelChain();

        $findings = ApiPlatformDeprecations::during(static function () use ($caller): void {
            new ReflectionMethod($caller, 'run')->invoke(null);
        });

        self::assertCount(1, $findings);
        self::assertStringContainsString('Since symfony/legacy-class 8.1: The class is deprecated.', $findings[0]);
        self::assertStringContainsString('/vendor/api-platform/core/Caller.php', $findings[0]);
    }

    public function testItReportsTheDeprecationAndWhereItWasRaised(): void
    {
        $findings = ApiPlatformDeprecations::during(static function (): void {
            trigger_deprecation('api-platform/core', '4.4', 'Calling "%s" is deprecated.', 'denormalize()');
        });

        self::assertCount(1, $findings);
        self::assertStringContainsString('Since api-platform/core 4.4: Calling "denormalize()" is deprecated.', $findings[0]);
        // L'origine est l'appelant de trigger_deprecation() — ce fichier —,
        // jamais le fichier de deprecation-contracts qui appelle trigger_error().
        self::assertStringContainsString(basename(__FILE__), $findings[0]);
        self::assertStringNotContainsString('deprecation-contracts', $findings[0]);
    }

    public function testItReportsEveryDeprecationNotOnlyTheFirst(): void
    {
        $findings = ApiPlatformDeprecations::during(static function (): void {
            trigger_deprecation('api-platform/core', '4.4', 'première');
            trigger_deprecation('api-platform/core', '4.4', 'seconde');
        });

        self::assertCount(2, $findings);
    }

    /**
     * `trigger_deprecation()` appelle `@trigger_error()` : la dépréciation
     * arrive donc toujours « supprimée » au gestionnaire, et PHPUnit la compte
     * quand même, `ignoreSuppressionOfDeprecations` étant posé. Le collecteur
     * fait de même ; s'il écartait ce qu'un `@` couvre, il ne verrait plus
     * aucune dépréciation d'API Platform.
     */
    public function testItKeepsADeprecationRaisedUnderTheAtOperator(): void
    {
        $findings = ApiPlatformDeprecations::during(static function (): void {
            // La forme brute, pour la preuve : le même `@` que dans deprecation-contracts.
            @trigger_error('Since api-platform/core 4.4: sous arobase', \E_USER_DEPRECATED);
        });

        self::assertCount(1, $findings);
        self::assertStringContainsString('sous arobase', $findings[0]);
    }

    /**
     * C8 : la pile est lue par le nom des trames, pas par leur rang. Un
     * gestionnaire posé par-dessus le nôtre (celui de Symfony, par exemple)
     * qui nous rend l'erreur ajoute une trame avant `trigger_error()`.
     */
    public function testItNamesTheDeprecatedMethodEvenWhenAnotherHandlerStandsInBetween(): void
    {
        $findings = ApiPlatformDeprecations::during(static function (): void {
            $collector = null;
            $collector = set_error_handler(static function (int $level, string $message, string $file, int $line) use (&$collector): bool {
                // Un gestionnaire intermédiaire, qui rend la main au collecteur.
                return null !== $collector && false !== $collector($level, $message, $file, $line);
            });

            try {
                self::deprecatedMethodOfTheTest();
            } finally {
                restore_error_handler();
            }
        });

        self::assertCount(1, $findings);
        self::assertStringContainsString('méthode dépréciée : '.self::class.'::deprecatedMethodOfTheTest()', $findings[0]);
    }

    public function testItHandsAnotherPackagesDeprecationBackToTheHandlerItReplaced(): void
    {
        $received = [];
        set_error_handler(static function (int $level, string $message) use (&$received): bool {
            $received[] = $message;

            return true;
        });

        try {
            $findings = ApiPlatformDeprecations::during(static function (): void {
                trigger_deprecation('symfony/serializer', '8.1', 'autre paquet');
            });
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $findings, 'Une dépréciation d\'un autre paquet n\'est pas celle d\'API Platform.');
        self::assertSame(['Since symfony/serializer 8.1: autre paquet'], $received, 'Elle doit rester visible du gestionnaire en place.');
    }

    public function testItHandsAnyOtherErrorBackToo(): void
    {
        $received = [];
        set_error_handler(static function (int $level, string $message) use (&$received): bool {
            $received[] = $level.' '.$message;

            return true;
        });

        try {
            $findings = ApiPlatformDeprecations::during(static function (): void {
                trigger_error('une notice', \E_USER_NOTICE);
            });
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $findings);
        self::assertSame([\E_USER_NOTICE.' une notice'], $received);
    }

    public function testItRestoresTheHandlerItFound(): void
    {
        $mine = static fn (): bool => true;
        set_error_handler($mine);

        try {
            ApiPlatformDeprecations::during(static function (): void {
            });
            $current = set_error_handler(static fn (): bool => false);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        self::assertSame($mine, $current);
    }

    public function testItRestoresTheHandlerWhenTheActionThrows(): void
    {
        $mine = static fn (): bool => true;
        set_error_handler($mine);

        try {
            try {
                ApiPlatformDeprecations::during(static function (): never {
                    throw new DomainException('échec de l\'action');
                });
                self::fail('L\'exception de l\'action doit remonter.');
            } catch (DomainException) {
            }
            $current = set_error_handler(static fn (): bool => false);
            restore_error_handler();
        } finally {
            restore_error_handler();
        }

        self::assertSame($mine, $current);
    }

    private static function deprecatedMethodOfTheTest(): void
    {
        trigger_deprecation('api-platform/core', '4.4', 'Calling "%s" is deprecated.', 'deprecatedMethodOfTheTest()');
    }

    protected function tearDown(): void
    {
        if (null !== $this->fixtureDirectory) {
            (new Filesystem())->remove($this->fixtureDirectory);
            $this->fixtureDirectory = null;
        }
        parent::tearDown();
    }

    /**
     * Écrit, sous un dossier temporaire, un fichier de classe qui lève sa
     * dépréciation au niveau du fichier (`vendor/symfony/legacy-class`), un
     * chargeur de fichiers (`vendor/composer`, l'autoloader) et un appelant
     * d'API Platform (`vendor/api-platform/core`) qui demande le chargement.
     *
     * @return class-string la classe d'API Platform, dont `run()` charge le fichier
     */
    private function writeFileLevelChain(): string
    {
        $namespace = 'ApiPlatformFileLevelFixture'.bin2hex(random_bytes(4));
        $this->fixtureDirectory = sys_get_temp_dir().'/'.$namespace;
        $filesystem = new Filesystem();

        $classFile = $this->fixtureDirectory.'/vendor/symfony/legacy-class/LegacyClass.php';
        $loaderFile = $this->fixtureDirectory.'/vendor/composer/Loader.php';
        $callerFile = $this->fixtureDirectory.'/vendor/api-platform/core/Caller.php';
        $filesystem->dumpFile($classFile, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            trigger_deprecation('symfony/legacy-class', '8.1', 'The class is deprecated.');

            final class LegacyClass
            {
            }
            PHP);
        $filesystem->dumpFile($loaderFile, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final class Loader
            {
                public static function load(string \$file): void
                {
                    require_once \$file;
                }
            }
            PHP);
        $filesystem->dumpFile($callerFile, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final class Caller
            {
                public static function run(): void
                {
                    Loader::load('{$classFile}');
                }
            }
            PHP);

        require_once $loaderFile;
        require_once $callerFile;

        /** @var class-string $caller */
        $caller = $namespace.'\\Caller';

        return $caller;
    }

    /**
     * L'action qui appelle l'API dépréciée depuis un fichier d'API Platform.
     *
     * @return Closure(): void
     */
    private function callThroughApiPlatform(): Closure
    {
        [$legacy, $caller] = $this->writeFixtureChain();

        return static function () use ($caller): void {
            new ReflectionMethod($caller, 'run')->invoke(null);
        };
    }

    /**
     * L'action qui appelle l'API dépréciée depuis ce fichier, du projet.
     *
     * @return Closure(): void
     */
    private function callFromTheProject(): Closure
    {
        [$legacy] = $this->writeFixtureChain();

        return static function () use ($legacy): void {
            new ReflectionMethod($legacy, 'legacy')->invoke(null);
        };
    }

    /**
     * Écrit, sous un dossier temporaire, une API « Symfony » dépréciée
     * (`vendor/symfony/foo`) et un appelant « API Platform »
     * (`vendor/api-platform/core`), et les charge sous un espace de noms
     * unique. Le dossier est supprimé par `tearDown()`.
     *
     * @return array{class-string, class-string} la classe dépréciée, la classe d'API Platform
     */
    private function writeFixtureChain(): array
    {
        $namespace = 'ApiPlatformDeprecationFixture'.bin2hex(random_bytes(4));
        $this->fixtureDirectory = sys_get_temp_dir().'/'.$namespace;
        $filesystem = new Filesystem();

        $legacyFile = $this->fixtureDirectory.'/vendor/symfony/foo/LegacyApi.php';
        $callerFile = $this->fixtureDirectory.'/vendor/api-platform/core/Caller.php';
        $filesystem->dumpFile($legacyFile, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final class LegacyApi
            {
                public static function legacy(): void
                {
                    trigger_deprecation('symfony/foo', '8.1', 'Calling "%s" is deprecated.', 'legacy()');
                }
            }
            PHP);
        $filesystem->dumpFile($callerFile, <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$namespace};

            final class Caller
            {
                public static function run(): void
                {
                    LegacyApi::legacy();
                }
            }
            PHP);

        require_once $legacyFile;
        require_once $callerFile;

        /** @var class-string $legacy */
        $legacy = $namespace.'\\LegacyApi';
        /** @var class-string $caller */
        $caller = $namespace.'\\Caller';

        return [$legacy, $caller];
    }
}
