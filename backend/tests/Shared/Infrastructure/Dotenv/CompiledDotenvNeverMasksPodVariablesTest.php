<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Dotenv;

use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\PhpImageBuild;
use App\Tests\Support\RepositoryFiles;
use App\Tests\Support\TestKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Le `.env.local.php` que compile l'image ne contient que `.env`, et une
 * variable posée par le pod prime sur lui, vide ou non (issue #489).
 *
 * Le test exécute la commande de compilation du Dockerfile telle quelle
 * ({@see ProductionImageCompilesDotenvTest::DUMP_COMMAND}), sur un projet
 * jetable qui reçoit le vrai `backend/.env` et le `vendor/` du dépôt, avec une
 * valeur piège dans l'environnement du « build ». Il démarre ensuite le vrai
 * `vendor/autoload_runtime.php`, celui que chargent `public/index.php` et
 * `bin/console`, dans un processus enfant dont l'environnement est
 * entièrement maîtrisé : celui d'un pod. Seul `project_dir` change, par
 * `APP_RUNTIME_OPTIONS`.
 *
 * Dans `.env`, `APP_SECRET`, `JWT_PASSPHRASE` ou `ANTHROPIC_API_KEY` sont
 * vides : le ConfigMap `backend-config` et le Secret `backend-secrets` en
 * donnent les vraies valeurs. Si le fichier compilé l'emportait, l'application
 * démarrerait avec un secret vide. `Dotenv::populate()` ne remplace pas une
 * variable déjà présente, sauf si le runtime reçoit l'option `dotenv_overload`.
 */
final class CompiledDotenvNeverMasksPodVariablesTest extends TestCase
{
    private const string BUILD_VALUE = 'leaked-from-the-build';

    /** Les variables que la sonde rapporte. */
    private const array PROBED = ['APP_ENV', 'APP_SECRET', 'LOG_LEVEL', 'MESSENGER_TRANSPORT_DSN'];

    private static string $project;

    public static function setUpBeforeClass(): void
    {
        $backend = RepositoryFiles::backend();
        self::$project = sys_get_temp_dir().'/compiled-dotenv-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->mkdir([self::$project, self::$project.'/home']);
        foreach (['composer.json', 'composer.lock', '.env'] as $file) {
            $filesystem->copy($backend.'/'.$file, self::$project.'/'.$file);
        }
        $filesystem->symlink($backend.'/vendor', self::$project.'/vendor');

        // Le build voit RABBITMQ_USER et RABBITMQ_PASSWORD, que `.env`
        // référence dans MESSENGER_TRANSPORT_DSN : la commande ne doit pas les
        // figer. HOME isole Composer de la configuration du poste.
        (new Process(
            ['sh', '-c', PhpImageBuild::commandContaining('dump-env')],
            self::$project,
            self::isolatedEnvironment(['HOME' => self::$project.'/home', 'RABBITMQ_USER' => self::BUILD_VALUE, 'RABBITMQ_PASSWORD' => self::BUILD_VALUE]),
        ))->mustRun();

        // Après la compilation, `.env` ne sert plus qu'au repli : une valeur
        // qui en vient se distingue de la valeur compilée.
        file_put_contents(self::$project.'/.env', "LOG_LEVEL=parsed-from-dotenv\n");
        file_put_contents(self::$project.'/probe.php', \sprintf(<<<'PHP'
            <?php

            require %s;

            return static function (array $context): void {
                echo json_encode(array_intersect_key($context, array_flip(%s)), \JSON_THROW_ON_ERROR);
            };
            PHP, var_export($backend.'/vendor/autoload_runtime.php', true), var_export(self::PROBED, true)));
    }

    public static function tearDownAfterClass(): void
    {
        (new Filesystem())->remove(self::$project);
    }

    /**
     * Le critère « aucun secret » : le fichier compilé n'a que les clés de
     * `.env`, avec ses valeurs, et rien de l'environnement du build.
     */
    public function testTheCompiledFileHoldsDotenvAndNothingFromTheBuild(): void
    {
        $compiled = file_get_contents(self::$project.'/.env.local.php');
        self::assertIsString($compiled);
        self::assertStringNotContainsString(self::BUILD_VALUE, $compiled);

        $values = include self::$project.'/.env.local.php';
        self::assertIsArray($values);
        $dotenv = (new Dotenv())->parse(RepositoryFiles::read(RepositoryFiles::backend('.env')));
        self::assertSame(array_keys($dotenv), array_keys($values), 'Les clés compilées sont celles de .env, dans le même ordre.');

        foreach ($dotenv as $name => $value) {
            if ('APP_ENV' !== $name && 'MESSENGER_TRANSPORT_DSN' !== $name) {
                self::assertSame($value, $values[$name], \sprintf('%s compilé diffère de .env.', $name));
            }
        }
        self::assertSame('prod', $values['APP_ENV']);
    }

    /**
     * Le témoin : sans lui, les tests de priorité passeraient aussi si le
     * fichier compilé n'était pas lu du tout.
     */
    public function testACompiledValueFillsWhatThePodLeavesUnset(): void
    {
        self::assertSame(
            ['APP_ENV' => 'prod', 'APP_SECRET' => '', 'LOG_LEVEL' => 'warning', 'MESSENGER_TRANSPORT_DSN' => 'amqp://:@rabbitmq:5672/%2f/messages'],
            $this->bootedEnvironment([]),
        );
    }

    /**
     * `${RABBITMQ_USER}` est compilé en expression, évaluée au démarrage
     * avec les variables du pod.
     */
    public function testADotenvReferenceIsResolvedWithThePodVariables(): void
    {
        self::assertSame(
            'amqp://pod-user:pod-password@rabbitmq:5672/%2f/messages',
            $this->bootedEnvironment(['RABBITMQ_USER' => 'pod-user', 'RABBITMQ_PASSWORD' => 'pod-password'])['MESSENGER_TRANSPORT_DSN'] ?? null,
        );
    }

    public function testAPodVariableWinsOverAnEmptyCompiledValue(): void
    {
        $secret = TestKeys::material();

        self::assertSame($secret, $this->bootedEnvironment(['APP_SECRET' => $secret])['APP_SECRET'] ?? null);
    }

    /**
     * Le cas de l'image `preprod` : son `ENV LOG_LEVEL=debug` doit l'emporter
     * sur le `warning` compilé depuis `.env`.
     */
    public function testAPodVariableWinsOverANonEmptyCompiledValue(): void
    {
        self::assertSame('debug', $this->bootedEnvironment(['LOG_LEVEL' => 'debug'])['LOG_LEVEL'] ?? null);
    }

    public function testAnEmptyPodVariableWinsOverANonEmptyCompiledValue(): void
    {
        self::assertSame('', $this->bootedEnvironment(['LOG_LEVEL' => ''])['LOG_LEVEL'] ?? null);
    }

    /**
     * Un pod lancé dans un autre environnement que celui compilé (un Job de
     * diagnostic en `dev`) ne reçoit pas les valeurs de `prod` : Dotenv
     * analyse `.env`, qui doit donc rester dans l'image.
     */
    public function testAnotherPodEnvironmentFallsBackToParsingDotenv(): void
    {
        $booted = $this->bootedEnvironment(['APP_ENV' => 'dev']);

        self::assertSame('dev', $booted['APP_ENV'] ?? null);
        self::assertSame('parsed-from-dotenv', $booted['LOG_LEVEL'] ?? null);
    }

    /**
     * @param array<string, string> $podVariables
     *
     * @return array<array-key, mixed>
     */
    private function bootedEnvironment(array $podVariables): array
    {
        $child = new Process(
            ChildPhpProcess::command(self::$project.'/probe.php'),
            env: self::isolatedEnvironment($podVariables + ['APP_ENV' => 'prod', 'APP_RUNTIME_OPTIONS' => json_encode(['project_dir' => self::$project], \JSON_THROW_ON_ERROR)]),
        );
        $child->mustRun();

        $decoded = json_decode($child->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * Ces variables, et seulement elles : sans cela l'enfant hériterait de
     * l'environnement de PHPUnit (`APP_ENV=test`, toutes les variables de
     * `.env`, `SYMFONY_DOTENV_VARS`, et dans le conteneur de dev les
     * identifiants RabbitMQ), qui fausserait chaque comparaison. Restent celles
     * qui servent à démarrer PHP.
     *
     * @param array<string, string> $variables
     *
     * @return array<string, string|false>
     */
    private static function isolatedEnvironment(array $variables): array
    {
        // `false` retire la variable de l'environnement que Process transmet.
        $inherited = [];
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $name) {
            if (\is_string($name) && !\in_array($name, ['PATH', 'PHPRC', 'PHP_INI_SCAN_DIR', 'LD_LIBRARY_PATH', 'TMPDIR'], true)) {
                $inherited[$name] = false;
            }
        }

        return $variables + $inherited;
    }
}
