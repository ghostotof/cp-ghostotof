<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\EnvironmentConfigKernel;
use PHPUnit\Framework\TestCase;

/**
 * Les gardes de l'exposition — ApiRouteExposureTest, AccessControlAnchoringTest,
 * McpExposureSymmetryTest, AccessMapStaysPathOnlyTest — lisent la configuration
 * de sécurité compilée dans le conteneur de **test** (issue #659, point I3 de
 * l'audit de #641). Elles ne prouvent rien de la production si un
 * environnement déclare une autre carte : une règle `access_control`, un
 * firewall ou une hiérarchie de rôles sous `when@prod` (préproduction et
 * production tournent en APP_ENV=prod) leur échapperait, et un bloc
 * `when@test` les rendrait vertes sur une carte que la production n'a pas.
 *
 * Toute la section `security` est donc comparée, pas seulement ces trois
 * clés : un fournisseur d'utilisateurs ou une stratégie de décision propre à
 * un environnement changerait tout autant ce que la production sert. Seules
 * les clés de PROPER_TO_ENVIRONMENT peuvent différer, chacune justifiée.
 *
 * Même lecture qu'ExceptionMappingEnvironmentParityTest : chaque environnement
 * est chargé par les chargeurs de Symfony, sans compilation, et chaque
 * fragment normalisé puis fusionné par la configuration de SecurityBundle
 * elle-même (EnvironmentConfigKernel::declaredConfiguration).
 *
 * Non vu, limite acceptée : une passe de compilation qui modifierait la carte
 * selon l'environnement (aucune ne le fait ; Kernel::build() n'en ajoute
 * qu'une, la même partout).
 */
final class SecurityConfigEnvironmentParityTest extends TestCase
{
    /**
     * Clé de `security` => pourquoi elle peut différer d'un environnement à
     * l'autre.
     */
    private const array PROPER_TO_ENVIRONMENT = [
        'password_hashers' => 'Coût bcrypt abaissé à 4 sous when@test pour la vitesse de la suite (issue #590) ; CpgUserPasswordHasherTest fige l\'entrée entière dans chaque environnement.',
    ];

    private const string FIXTURE_PROJECT = __DIR__.'/Fixtures/EnvironmentProject';

    /**
     * @var array<string, EnvironmentConfigKernel>
     */
    private array $kernels = [];

    public function testEveryEnvironmentDeclaresTheSameSecurityAsTest(): void
    {
        self::assertSame(
            [],
            $this->differences(null),
            'Configuration de sécurité qui diffère du conteneur de test : les gardes de l\'exposition ne la voient pas. La rendre commune à tous les environnements.',
        );
    }

    /**
     * La comparaison elle-même, sur un projet fixture qui déclare une
     * différence sous chaque forme que le noyau charge selon l'environnement —
     * chacune sur sa propre entrée —, à côté de déclarations communes ou
     * exemptées, qui ne ressortent pas.
     */
    public function testTheComparisonSeesEveryFormTheKernelLoads(): void
    {
        self::assertSame([
            // packages/dev/security.yaml, répertoire propre à `dev`.
            'dev ≠ test : security.access_control[0]',
            // packages/security.php, when@dev au format tableau.
            'dev ≠ test : security.access_decision_manager[strategy]',
            // services_prod.yaml.
            'prod ≠ test : security.access_control[1]',
            // packages/prod/security.php, au format closure.
            'prod ≠ test : security.firewalls[api]',
            // packages/security.yaml, when@prod.
            'prod ≠ test : security.role_hierarchy[ROLE_USER]',
        ], $this->differences(self::FIXTURE_PROJECT));
    }

    /**
     * Une exemption qui ne sert plus se retire : sinon elle couvrirait demain
     * une différence que personne n'a justifiée.
     */
    public function testEveryKeyProperToAnEnvironmentStillDiffers(): void
    {
        $reference = $this->declared('test', null);
        foreach (array_keys(self::PROPER_TO_ENVIRONMENT) as $key) {
            $differs = false;
            foreach ($this->environments() as $environment) {
                $differs = $differs || ($this->declared($environment, null)[$key] ?? null) !== ($reference[$key] ?? null);
            }
            self::assertTrue($differs, \sprintf('security.%s est identique partout : retirer son exemption de PROPER_TO_ENVIRONMENT.', $key));
        }
    }

    /**
     * Une valeur paramétrée (`%env(…)%`, `%paramètre%`) se lit à l'identique
     * dans chaque environnement, mais se résout selon lui : la parité ne la
     * verrait pas, et le conteneur de test en retiendrait sa valeur à lui. La
     * configuration comparée reste donc littérale.
     */
    public function testNoSecurityValueIsParameterized(): void
    {
        self::assertSame([], $this->parameterized(null), 'Valeur paramétrée dans la configuration de sécurité : l\'écrire en clair.');
    }

    public function testAParameterizedValueIsFound(): void
    {
        self::assertSame(['security.access_control[1]'], $this->parameterized(self::FIXTURE_PROJECT));
    }

    /**
     * @return list<string> « security.clé[entrée] » dont la valeur est paramétrée, dans un environnement au moins
     */
    private function parameterized(?string $projectDirectory): array
    {
        $found = [];
        foreach (['test', ...$this->environments()] as $environment) {
            foreach ($this->entries($environment, $projectDirectory) as $entry => $value) {
                if (str_contains((string) json_encode($value), '%')) {
                    $found[] = $entry;
                }
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * @return list<string> « env ≠ test : security.clé[entrée] », triées
     */
    private function differences(?string $projectDirectory): array
    {
        $found = [];
        $reference = $this->entries('test', $projectDirectory);
        foreach ($this->environments() as $environment) {
            $declared = $this->entries($environment, $projectDirectory);
            foreach (array_unique([...array_keys($declared), ...array_keys($reference)]) as $entry) {
                if (($declared[$entry] ?? null) !== ($reference[$entry] ?? null)) {
                    $found[] = \sprintf('%s ≠ test : %s', $environment, $entry);
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * La configuration comparée, hors exemptions, à plat sur un niveau : une
     * règle `access_control` par index, un firewall, un fournisseur ou un
     * rôle par nom — le diagnostic désigne ainsi l'entrée en cause.
     *
     * @return array<string, mixed> « security.clé[entrée] » ou « security.clé » => valeur
     */
    private function entries(string $environment, ?string $projectDirectory): array
    {
        $entries = [];
        foreach (array_diff_key($this->declared($environment, $projectDirectory), self::PROPER_TO_ENVIRONMENT) as $key => $value) {
            if (!\is_array($value)) {
                $entries['security.'.$key] = $value;

                continue;
            }
            foreach ($value as $entry => $entryValue) {
                $entries[\sprintf('security.%s[%s]', $key, $entry)] = $entryValue;
            }
        }

        return $entries;
    }

    /**
     * Les environnements que le noyau admet, hors `test`
     * ({@see EnvironmentConfigKernel::allowedEnvironments()}).
     *
     * @return list<string>
     */
    private function environments(): array
    {
        return array_values(array_diff(EnvironmentConfigKernel::allowedEnvironments(), ['test']));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function declared(string $environment, ?string $projectDirectory): array
    {
        $kernel = $this->kernels[$environment.'|'.$projectDirectory] ??= new EnvironmentConfigKernel($environment, $projectDirectory);

        return $kernel->declaredConfiguration('security');
    }
}
