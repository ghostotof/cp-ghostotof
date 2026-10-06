<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Kernel;
use App\Tests\Support\EnvironmentConfigKernel;
use PHPUnit\Framework\TestCase;

/**
 * Les garde-fous de log_level lisent `framework.exceptions` et
 * `api_platform.exception_to_status` compilés dans le conteneur de **test**
 * (issue #357). Ils ne valent pour la production que si chaque environnement
 * déclare exactement les mêmes : c'est ce que ce test vérifie.
 *
 * Deux sens, également dangereux :
 *  - une entrée propre à `prod` (préproduction et production tournent en
 *    APP_ENV=prod) échappe aux garde-fous ;
 *  - une entrée propre à `test` les rend verts sur une configuration que la
 *    production n'a pas.
 *
 * La configuration n'est pas lue fichier par fichier : chaque environnement
 * est chargé par les chargeurs de Symfony, sans compilation
 * (EnvironmentConfigKernel). Toutes les formes que le noyau connaît y passent,
 * YAML comme PHP, et rien d'autre.
 */
final class ExceptionMappingEnvironmentParityTest extends TestCase
{
    /**
     * Extension => clé du mapping.
     */
    private const array MAPPINGS = ['framework' => 'exceptions', 'api_platform' => 'exception_to_status'];

    private const string FIXTURE_PROJECT = __DIR__.'/Fixtures/EnvironmentProject';

    /**
     * @var array<string, EnvironmentConfigKernel>
     */
    private array $kernels = [];

    public function testEveryEnvironmentDeclaresTheSameMappingsAsTest(): void
    {
        self::assertSame(
            [],
            $this->differences(null),
            'Mapping qui diffère du conteneur de test : les garde-fous de log_level ne le voient pas. Le rendre commun à tous les environnements.',
        );
    }

    /**
     * La comparaison elle-même, sur un projet fixture qui déclare un mapping
     * sous chaque forme que le noyau charge selon l'environnement — chacune
     * avec sa propre classe —, à côté de déclarations communes, qui ne
     * ressortent pas.
     */
    public function testTheComparisonSeesEveryFormTheKernelLoads(): void
    {
        self::assertSame([
            // services.yaml, when@dev.
            'dev ≠ test : api_platform.exception_to_status[InvalidArgumentException]',
            'dev ≠ test : api_platform.exception_to_status[LogicException]',
            'dev ≠ test : api_platform.exception_to_status[RuntimeException]',
            'dev ≠ test : framework.exceptions[LogicException]',
            // packages/test/exceptions.php, propre à `test`.
            'prod ≠ test : api_platform.exception_to_status[InvalidArgumentException]',
            // packages/api_platform.php, when@prod au format tableau.
            'prod ≠ test : api_platform.exception_to_status[LengthException]',
            // packages/test/api_platform.yaml, propre à `test`.
            'prod ≠ test : api_platform.exception_to_status[LogicException]',
            // packages/prod/api_platform.yaml.
            'prod ≠ test : api_platform.exception_to_status[UnderflowException]',
            // packages/framework.yaml, entrée commune surchargée sous when@prod.
            'prod ≠ test : framework.exceptions[BadFunctionCallException]',
            // packages/framework.yaml, when@test.
            'prod ≠ test : framework.exceptions[LogicException]',
            // services_prod.yaml.
            'prod ≠ test : framework.exceptions[OverflowException]',
            // packages/prod/framework.php, au format closure.
            'prod ≠ test : framework.exceptions[RangeException]',
            // packages/framework.yaml, when@prod.
            'prod ≠ test : framework.exceptions[RuntimeException]',
        ], $this->differences(self::FIXTURE_PROJECT));
    }

    /**
     * @return list<string> « env ≠ test : extension.clé[classe] », triées
     */
    private function differences(?string $projectDirectory): array
    {
        $found = [];
        foreach (self::MAPPINGS as $extension => $key) {
            $reference = $this->declared('test', $extension, $key, $projectDirectory);
            foreach ($this->environments() as $environment) {
                $declared = $this->declared($environment, $extension, $key, $projectDirectory);
                foreach (array_unique([...array_keys($declared), ...array_keys($reference)]) as $class) {
                    if (($declared[$class] ?? null) !== ($reference[$class] ?? null)) {
                        $found[] = \sprintf('%s ≠ test : %s.%s[%s]', $environment, $extension, $key, $class);
                    }
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Les environnements que le noyau admet, hors `test` : lus dans
     * App\Kernel::getAllowedEnvs() plutôt que recopiés, pour qu'un
     * environnement ajouté soit comparé sans qu'on y pense.
     *
     * @return list<string>
     */
    private function environments(): array
    {
        /** @var list<string> $allowed */
        $allowed = (new \ReflectionMethod(Kernel::class, 'getAllowedEnvs'))->invoke(new Kernel('test', false));
        self::assertContains('prod', $allowed, 'Le noyau n\'admet plus `prod` : revoir ce test.');

        return array_values(array_diff($allowed, ['test']));
    }

    /**
     * Le mapping que déclare un environnement, ses fragments fusionnés dans
     * leur ordre de chargement : le dernier l'emporte, entrée par entrée.
     *
     * @return array<array-key, mixed> classe => options ou statut
     */
    private function declared(string $environment, string $extension, string $key, ?string $projectDirectory): array
    {
        $kernel = $this->kernels[$environment.'|'.$projectDirectory] ??= new EnvironmentConfigKernel($environment, $projectDirectory);

        $merged = [];
        foreach ($kernel->declaredConfig($extension) as $fragment) {
            if (\is_array($fragment[$key] ?? null)) {
                $merged = array_replace_recursive($merged, $fragment[$key]);
            }
        }

        return $merged;
    }
}
