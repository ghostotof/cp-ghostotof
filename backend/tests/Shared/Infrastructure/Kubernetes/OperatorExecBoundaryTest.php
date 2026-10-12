<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Issue #520 : où passe la frontière du `kubectl exec`.
 *
 * L'identité CI n'a jamais `pods/exec` (audit C8), et une commande à effet de
 * bord passe par un Job (#386, la ligne d'audit resterait sinon sur le
 * terminal de l'opérateur). Un humain muni d'un kubeconfig d'administrateur
 * garde, lui, l'`exec` de diagnostic en lecture seule, dont la sortie reste sur
 * son terminal — c'est le cas de « nommer un compte » du runbook : un Job
 * aurait écrit le nom et l'adresse dans les journaux du pod, lisibles par le
 * jeton CI (`pods/log`).
 *
 * Ce test pince les deux moitiés : le Role du déployeur n'ouvre ni shell ni
 * tunnel, et la commande du runbook reste une lecture, sur l'entrée standard.
 */
final class OperatorExecBoundaryTest extends TestCase
{
    /** Ce qu'un humain fait à la main et que la CI ne doit jamais pouvoir faire. */
    private const array INTERACTIVE_POD_ACCESS = ['pods/exec', 'pods/attach', 'pods/portforward', '*'];

    private const string LOOKUP_SECTION = 'Pour nommer un compte pendant un incident';

    public function testTheDeployerRoleOpensNeitherAShellNorATunnel(): void
    {
        $granted = [];
        foreach ($this->roleRules() as $rule) {
            $groups = $rule['apiGroups'] ?? [];
            self::assertIsArray($groups);
            if (!\in_array('', $groups, true) && !\in_array('*', $groups, true)) {
                continue;
            }
            $resources = $rule['resources'] ?? [];
            self::assertIsArray($resources);
            $granted = [...$granted, ...$resources];
        }

        self::assertNotEmpty($granted, 'Le Role doit porter des règles sur le groupe core.');
        self::assertSame([], array_values(array_intersect(self::INTERACTIVE_POD_ACCESS, $granted)));
    }

    /** Une transaction en lecture seule : la commande ne peut rien écrire, même par erreur. */
    public function testTheAccountLookupRunsInAReadOnlyTransaction(): void
    {
        $command = $this->lookupCommand();

        self::assertStringContainsString('PGOPTIONS="-c default_transaction_read_only=on"', $command);
        // -X : aucun ~/.psqlrc du conteneur ; -At : une ligne brute par compte.
        self::assertStringContainsString('psql -X -At -U "$POSTGRES_USER" -d "$POSTGRES_DB"', $command);
    }

    /**
     * Un incident vise plus souvent la prod : le namespace est un paramètre,
     * et le conteneur est nommé plutôt que laissé au défaut du pod.
     */
    public function testTheAccountLookupTargetsTheNamespaceGivenByTheOperator(): void
    {
        $command = $this->lookupCommand();

        self::assertStringContainsString('kubectl -n "$NS" exec -i deploy/postgres -c postgres --', $command);
        self::assertDoesNotMatchRegularExpression('/-n (preprod|prod)\b/', $command);
    }

    /**
     * La requête arrive par l'entrée standard : ni l'UUID ni le SQL ne figurent
     * dans l'argv de l'exec, donc dans l'URL de la requête à l'API server.
     */
    public function testTheQueryReachesPsqlOnStandardInput(): void
    {
        $command = $this->lookupCommand();

        self::assertMatchesRegularExpression('/^echo "SELECT username, email FROM cpg_user WHERE id = \'<userId>\';" \\\\\n\s*\| kubectl /m', $command);
    }

    /**
     * Le bloc `bash` qui suit la phrase d'introduction de la section.
     */
    private function lookupCommand(): string
    {
        $readme = (string) file_get_contents($this->k8sDir().'/README.md');
        $start = strpos($readme, self::LOOKUP_SECTION);
        self::assertIsInt($start, 'La section « nommer un compte » a disparu du runbook.');

        self::assertSame(1, preg_match('/```bash\n(.*?)```/s', substr($readme, $start), $block));

        return $block[1];
    }

    /**
     * @return list<array<mixed>>
     */
    private function roleRules(): array
    {
        $path = $this->k8sDir().'/base/github-actions-rbac/role.yaml';
        self::assertFileIsReadable($path);
        $role = Yaml::parse((string) file_get_contents($path));
        self::assertIsArray($role);
        self::assertSame('Role', $role['kind'] ?? null);

        $rules = $role['rules'] ?? null;
        self::assertIsArray($rules);
        $typed = [];
        foreach ($rules as $rule) {
            self::assertIsArray($rule);
            $typed[] = $rule;
        }

        return $typed;
    }

    private function k8sDir(): string
    {
        return \dirname(__DIR__, 5).'/k8s';
    }
}
