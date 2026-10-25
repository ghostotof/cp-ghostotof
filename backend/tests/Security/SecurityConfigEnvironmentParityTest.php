<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Security\Authentication\BearerCookieSecurePerEnvironmentTest;
use App\Tests\Security\Authentication\CpgUserPasswordHasherTest;
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
 * clés : une stratégie de décision ou la déclaration d'un fournisseur propre à
 * un environnement changerait tout autant ce que la production sert. Et
 * `lexik_jwt_authentication` avec elle : l'authentificateur `jwt` du firewall
 * `api` y prend où lire le jeton (cookie, en-tête) et ce qu'il pose. Seuls les
 * chemins d'ENVIRONMENT_SPECIFIC peuvent différer, chacun justifié.
 *
 * Même lecture qu'ExceptionMappingEnvironmentParityTest : chaque environnement
 * est chargé par les chargeurs de Symfony, sans compilation, et chaque
 * fragment normalisé puis fusionné par la configuration de l'extension
 * elle-même (EnvironmentConfigKernel::declaredConfiguration).
 *
 * Non vu, limites acceptées (aucun cas aujourd'hui) :
 *  - un service que la configuration désigne, redéfini pour un environnement
 *    (`when@prod: services:`, services_prod.yaml) — un fournisseur, un voter,
 *    un user_checker : la section reste identique, le comportement non ;
 *  - une passe de compilation qui modifierait la carte selon l'environnement
 *    ({@see \App\Kernel::build()} n'en ajoute qu'une, la même partout) ;
 *  - ce qu'un bundle ajoute par `prepend()`, appliqué à la compilation, après
 *    les fragments lus ici.
 */
final class SecurityConfigEnvironmentParityTest extends TestCase
{
    /**
     * Les extensions comparées.
     */
    private const array EXTENSIONS = ['security', 'lexik_jwt_authentication'];

    /**
     * « extension.chemin.vers.la.clé » qui peut différer d'un environnement à
     * l'autre => pourquoi, et le test qui fige sa valeur dans chaque
     * environnement. Le chemin est retiré de la comparaison, rien de plus
     * large. Sans ce test, l'exemption n'a pas de filet : la valeur de prod
     * peut changer sans que rien rougisse ici (issue #703). Écrit en `::class`
     * pour qu'un renommage ou une suppression du test fasse rougir PHPStan.
     *
     * @var array<string, array{string, class-string<TestCase>}>
     */
    private const array ENVIRONMENT_SPECIFIC = [
        'security.password_hashers' => [
            'Coût bcrypt abaissé à 4 sous when@test pour la vitesse de la suite (issue #590).',
            CpgUserPasswordHasherTest::class,
        ],
        'lexik_jwt_authentication.set_cookies.BEARER.secure' => [
            'Secure en prod seulement, la règle d\'AuthCookieFactory (le dev sert localhost en HTTP) ; AuthCookieAttributesTest le vérifie sur le cookie réellement posé, en test seulement.',
            BearerCookieSecurePerEnvironmentTest::class,
        ],
    ];

    /**
     * « extension.clé[entrée] » dont la valeur est paramétrée par construction
     * => pourquoi.
     */
    private const array PARAMETERIZED_BY_DESIGN = [
        'lexik_jwt_authentication.pass_phrase' => 'Secret propre à chaque déploiement : il vient de l\'environnement, jamais du dépôt.',
        'lexik_jwt_authentication.public_key' => 'Chemin de la clé publique, propre à chaque déploiement comme la paire qu\'elle complète.',
        'lexik_jwt_authentication.secret_key' => 'Chemin de la clé privée, secret propre à chaque déploiement.',
    ];

    private const string FIXTURE_PROJECT = __DIR__.'/Fixtures/EnvironmentProject';

    /**
     * Un noyau par environnement et projet, gardé d'un test à l'autre :
     * chaque chargement du projet réel parcourt tout src/.
     *
     * @var array<string, EnvironmentConfigKernel>
     */
    private static array $kernels = [];

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
            // packages/lexik_jwt_authentication.yaml, when@test.
            'dev ≠ test : lexik_jwt_authentication.token_extractors[authorization_header]',
            // packages/dev/security.yaml, répertoire propre à `dev`.
            'dev ≠ test : security.access_control[0]',
            // packages/security.php, when@dev au format tableau.
            'dev ≠ test : security.access_decision_manager[strategy]',
            'prod ≠ test : lexik_jwt_authentication.token_extractors[authorization_header]',
            // services_prod.yaml.
            'prod ≠ test : security.access_control[1]',
            // packages/prod/security.php, au format closure.
            'prod ≠ test : security.firewalls[api]',
            // packages/security.yaml, when@prod.
            'prod ≠ test : security.role_hierarchy[ROLE_USER]',
        ], $this->differences(self::FIXTURE_PROJECT));
    }

    /**
     * L'ordre des entrées, que la comparaison entrée par entrée ne voit pas,
     * sur deux configurations construites ici — la fusion de Config garde la
     * place d'une clé existante, aucune fixture ne le produirait. Firewalls
     * inversés : rapportés ; un rôle ajouté en fin de hiérarchie, les autres
     * dans le même ordre : rien, l'ajout est rapporté pour lui-même.
     */
    public function testTheComparisonSeesTheOrderOfTheFirewalls(): void
    {
        $reference = [
            'firewalls' => ['login' => ['pattern' => '^/api/login_check$'], 'api' => ['pattern' => '^/api']],
            'role_hierarchy' => ['ROLE_TRUSTED' => ['ROLE_USER']],
            'access_control' => [['path' => '^/a'], ['path' => '^/b']],
        ];
        $other = [
            'firewalls' => ['api' => ['pattern' => '^/api'], 'login' => ['pattern' => '^/api/login_check$']],
            'role_hierarchy' => ['ROLE_TRUSTED' => ['ROLE_USER'], 'ROLE_SUPER' => ['ROLE_TRUSTED']],
            'access_control' => [['path' => '^/a'], ['path' => '^/b']],
        ];

        self::assertSame(['firewalls'], $this->orderDifferences($reference, $other));
    }

    /**
     * Une exemption qui ne sert plus se retire : sinon elle couvrirait demain
     * une différence que personne n'a justifiée. Mais une exemption qui ne
     * sert plus et un écart voulu qui a disparu rougissent de la même façon,
     * et retirer `Secure` en prod en est un (issue #703) : le message renvoie
     * donc à la raison écrite et au test qui fige la valeur avant d'inviter à
     * retirer quoi que ce soit.
     */
    public function testEveryEnvironmentSpecificPathStillDiffers(): void
    {
        foreach (self::ENVIRONMENT_SPECIFIC as $path => [$reason, $pinnedBy]) {
            [$extension, $keys] = $this->split($path);
            $reference = $this->at($this->declared('test', $extension, null), $keys);
            $differs = false;
            foreach (EnvironmentConfigKernel::comparedEnvironments() as $environment) {
                $differs = $differs || $this->at($this->declared($environment, $extension, null), $keys) !== $reference;
            }
            self::assertTrue($differs, \sprintf(
                '%s est identique dans tous les environnements. Son exemption d\'ENVIRONMENT_SPECIFIC dit pourquoi il devait différer : « %s » Si cet écart est toujours voulu, c\'est la configuration qu\'il faut rétablir : %s fige la valeur attendue de chaque environnement. Ne retirer l\'exemption qu\'après avoir vérifié que sa raison ne tient plus, et adapter ce test plutôt que le supprimer.',
                $path,
                $reason,
                $pinnedBy,
            ));
        }
    }

    /**
     * Une valeur paramétrée (`%env(…)%`, `%paramètre%`) se lit à l'identique
     * dans chaque environnement, mais se résout selon lui : la parité ne la
     * verrait pas, et le conteneur de test en retiendrait sa valeur à lui. La
     * configuration comparée reste donc littérale, clés comprises, hors
     * PARAMETERIZED_BY_DESIGN.
     */
    public function testNoComparedValueIsParameterized(): void
    {
        self::assertSame(
            [],
            array_values(array_diff($this->parameterized(null), array_keys(self::PARAMETERIZED_BY_DESIGN))),
            'Valeur paramétrée dans la configuration de sécurité : l\'écrire en clair.',
        );
    }

    public function testEveryParameterizedByDesignEntryStillIs(): void
    {
        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::PARAMETERIZED_BY_DESIGN), $this->parameterized(null))),
            'Entrée de PARAMETERIZED_BY_DESIGN écrite en clair désormais : la retirer.',
        );
    }

    /**
     * Une valeur ou une clé paramétrée est trouvée ; un `%%` échappé, qui se
     * lit `%` partout, ne l'est pas (fixture : le firewall `login`).
     */
    public function testAParameterizedValueOrKeyIsFound(): void
    {
        self::assertSame([
            'lexik_jwt_authentication.pass_phrase',
            'security.access_control[1]',
            'security.role_hierarchy[%fixture.role%]',
        ], $this->parameterized(self::FIXTURE_PROJECT));
    }

    /**
     * @return list<string> « extension.clé[entrée] » dont la clé ou la valeur est paramétrée, dans un environnement au moins, triées
     */
    private function parameterized(?string $projectDirectory): array
    {
        $found = [];
        foreach (['test', ...EnvironmentConfigKernel::comparedEnvironments()] as $environment) {
            foreach ($this->entries($environment, $projectDirectory) as $entry => $value) {
                if (self::isParameterized($entry) || self::isParameterized($value)) {
                    $found[] = $entry;
                }
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /**
     * Une référence de paramètre, `%…%`, dans une chaîne, une clé ou une
     * valeur imbriquée. `%%` est l'échappement de Symfony : il se lit `%` dans
     * tout environnement, il est donc écarté d'abord.
     */
    private static function isParameterized(mixed $value): bool
    {
        if (\is_string($value)) {
            return 1 === preg_match('/%[^%\s]+%/', str_replace('%%', '', $value));
        }
        if (!\is_array($value)) {
            return false;
        }

        return array_any($value, static fn (mixed $item, int|string $key): bool => self::isParameterized((string) $key) || self::isParameterized($item));
    }

    /**
     * @return list<string> « env ≠ test : extension.clé[entrée] », triées
     */
    private function differences(?string $projectDirectory): array
    {
        $found = [];
        $reference = $this->entries('test', $projectDirectory);
        foreach (EnvironmentConfigKernel::comparedEnvironments() as $environment) {
            $declared = $this->entries($environment, $projectDirectory);
            foreach (array_unique([...array_keys($declared), ...array_keys($reference)]) as $entry) {
                if (($declared[$entry] ?? null) !== ($reference[$entry] ?? null)) {
                    $found[] = \sprintf('%s ≠ test : %s', $environment, $entry);
                }
            }
            foreach (self::EXTENSIONS as $extension) {
                $ordered = $this->orderDifferences(
                    $this->compared('test', $extension, $projectDirectory),
                    $this->compared($environment, $extension, $projectDirectory),
                );
                foreach ($ordered as $key) {
                    $found[] = \sprintf('%s ≠ test : %s.%s (ordre)', $environment, $extension, $key);
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * Les clés dont les entrées communes se suivent autrement d'un côté et de
     * l'autre. L'ordre décide pour les firewalls — Symfony retient le premier
     * dont le motif correspond —, et la comparaison entrée par entrée ne le
     * voit pas. Seules les entrées communes comptent : une entrée en plus est
     * déjà rapportée pour elle-même. Une liste (`access_control`) est déjà
     * comparée index par index.
     *
     * @param array<array-key, mixed> $reference
     * @param array<array-key, mixed> $other
     *
     * @return list<string> les clés, dans l'ordre de la référence
     */
    private function orderDifferences(array $reference, array $other): array
    {
        $found = [];
        foreach ($reference as $key => $value) {
            $otherValue = $other[$key] ?? null;
            if (!\is_array($value) || !\is_array($otherValue) || array_is_list($value) || array_is_list($otherValue)) {
                continue;
            }
            if (array_keys(array_intersect_key($value, $otherValue)) !== array_keys(array_intersect_key($otherValue, $value))) {
                $found[] = (string) $key;
            }
        }

        return $found;
    }

    /**
     * Ce que l'environnement déclare pour une extension, hors exemptions
     * d'ENVIRONMENT_SPECIFIC.
     *
     * @return array<array-key, mixed>
     */
    private function compared(string $environment, string $extension, ?string $projectDirectory): array
    {
        $declared = $this->declared($environment, $extension, $projectDirectory);
        foreach (array_keys(self::ENVIRONMENT_SPECIFIC) as $path) {
            [$exemptExtension, $keys] = $this->split($path);
            if ($exemptExtension === $extension) {
                $declared = self::without($declared, $keys);
            }
        }

        return $declared;
    }

    /**
     * La configuration comparée, hors exemptions, à plat sur un niveau : une
     * règle `access_control` par index, un firewall, un fournisseur ou un
     * rôle par nom — le diagnostic désigne ainsi l'entrée en cause.
     *
     * @return array<string, mixed> « extension.clé[entrée] » ou « extension.clé » => valeur
     */
    private function entries(string $environment, ?string $projectDirectory): array
    {
        $entries = [];
        foreach (self::EXTENSIONS as $extension) {
            foreach ($this->compared($environment, $extension, $projectDirectory) as $key => $value) {
                if (!\is_array($value)) {
                    $entries[\sprintf('%s.%s', $extension, $key)] = $value;

                    continue;
                }
                foreach ($value as $entry => $entryValue) {
                    $entries[\sprintf('%s.%s[%s]', $extension, $key, $entry)] = $entryValue;
                }
            }
        }

        return $entries;
    }

    /**
     * @return array{string, list<string>} l'extension, puis le chemin de clés en dessous
     */
    private function split(string $path): array
    {
        $keys = explode('.', $path);

        return [array_shift($keys), $keys];
    }

    /**
     * La valeur au bout d'un chemin de clés, `null` si le chemin n'existe pas.
     *
     * @param list<string> $keys
     */
    private function at(mixed $value, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * La configuration sans la clé au bout du chemin — et sans rien d'autre.
     *
     * @param array<array-key, mixed> $config
     * @param list<string>            $keys
     *
     * @return array<array-key, mixed>
     */
    private static function without(array $config, array $keys): array
    {
        $key = array_shift($keys);
        if (null === $key || !\array_key_exists($key, $config)) {
            return $config;
        }
        if ([] === $keys) {
            unset($config[$key]);

            return $config;
        }
        if (\is_array($config[$key])) {
            $config[$key] = self::without($config[$key], $keys);
        }

        return $config;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function declared(string $environment, string $extension, ?string $projectDirectory): array
    {
        $kernel = self::$kernels[$environment.'|'.$projectDirectory] ??= new EnvironmentConfigKernel($environment, $projectDirectory);

        return $kernel->declaredConfiguration($extension);
    }
}
