<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Dotenv;

use App\Tests\Support\ChildPhpProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Une variable posée par le pod prime sur la valeur compilée dans
 * `.env.local.php`, vide ou non (issue #489).
 *
 * L'image de production compile `.env` ({@see ProductionImageCompilesDotenvTest}),
 * où `APP_SECRET`, `JWT_PASSPHRASE` ou `ANTHROPIC_API_KEY` sont vides. Le
 * ConfigMap `backend-config` et le Secret `backend-secrets` en donnent les
 * vraies valeurs. Si le fichier compilé l'emportait, l'application démarrerait
 * avec un secret vide. `Dotenv::populate()` ne remplace pas une variable déjà
 * présente, sauf si le runtime reçoit l'option `dotenv_overload`.
 *
 * Le test rejoue le chemin réel : le `vendor/autoload_runtime.php` que
 * chargent `public/index.php` et `bin/console`, avec les options du runtime
 * que Composer y a figées, dans un processus enfant dont l'environnement
 * est entièrement maîtrisé. Seul `project_dir` change, par
 * `APP_RUNTIME_OPTIONS`, pour pointer sur un projet jetable.
 */
final class CompiledDotenvNeverMasksPodVariablesTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir().'/compiled-dotenv-'.bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->project);

        // Illisible pour Dotenv : si le fichier compilé est écarté au profit
        // de `.env`, l'enfant échoue au lieu de passer sur des valeurs voisines.
        file_put_contents($this->project.'/.env', "NOT A VALID DOTENV LINE\n");
        // La forme que produit `composer dump-env prod`.
        file_put_contents($this->project.'/.env.local.php', <<<'PHP'
            <?php

            return array(
              'APP_ENV' => 'prod',
              'APP_SECRET' => '',
              'LOG_LEVEL' => 'warning',
              'DEFAULT_URI' => 'http://compiled.example',
            );
            PHP);
        file_put_contents($this->project.'/probe.php', \sprintf(<<<'PHP'
            <?php

            require %s;

            return static function (array $context): void {
                echo json_encode(array_intersect_key($context, array_flip(['APP_ENV', 'APP_SECRET', 'LOG_LEVEL', 'DEFAULT_URI'])), \JSON_THROW_ON_ERROR);
            };
            PHP, var_export(\dirname(__DIR__, 4).'/vendor/autoload_runtime.php', true)));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->project);
    }

    public function testAPodVariableWinsOverAnEmptyCompiledValue(): void
    {
        self::assertSame('set-by-the-pod', $this->bootedEnvironment(['APP_SECRET' => 'set-by-the-pod'])['APP_SECRET'] ?? null);
    }

    /**
     * Le cas de l'image `preprod` : son `ENV LOG_LEVEL=debug` doit l'emporter
     * sur le `warning` compilé depuis `.env`.
     */
    public function testAPodVariableWinsOverANonEmptyCompiledValue(): void
    {
        self::assertSame('debug', $this->bootedEnvironment(['LOG_LEVEL' => 'debug'])['LOG_LEVEL'] ?? null);
    }

    /**
     * Le témoin : sans lui, les deux tests précédents passeraient aussi si le
     * fichier compilé n'était pas lu du tout.
     */
    public function testACompiledValueFillsWhatThePodLeavesUnset(): void
    {
        self::assertSame(
            ['APP_ENV' => 'prod', 'APP_SECRET' => '', 'LOG_LEVEL' => 'warning', 'DEFAULT_URI' => 'http://compiled.example'],
            $this->bootedEnvironment([]),
        );
    }

    /**
     * @param array<string, string> $podVariables
     *
     * @return array<array-key, mixed>
     */
    private function bootedEnvironment(array $podVariables): array
    {
        $child = new Process(
            ChildPhpProcess::command($this->project.'/probe.php'),
            env: $this->isolatedEnvironment(['APP_ENV' => 'prod'] + $podVariables),
        );
        $child->mustRun();

        $decoded = json_decode($child->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * L'environnement du pod, et lui seul : sans cela l'enfant hériterait de
     * celui de PHPUnit (`APP_ENV=test`, toutes les variables de `.env`,
     * `SYMFONY_DOTENV_VARS`), qui fausserait chaque comparaison.
     *
     * @param array<string, string> $podVariables
     *
     * @return array<string, string|false>
     */
    private function isolatedEnvironment(array $podVariables): array
    {
        // `false` retire la variable de l'environnement que Process transmet.
        $inherited = [];
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $name) {
            if (\is_string($name) && 'PATH' !== $name) {
                $inherited[$name] = false;
            }
        }

        return ['APP_RUNTIME_OPTIONS' => json_encode(['project_dir' => $this->project], \JSON_THROW_ON_ERROR)]
            + $podVariables
            + $inherited;
    }
}
