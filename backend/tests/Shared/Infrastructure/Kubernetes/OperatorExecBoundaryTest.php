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
 * Ce test pince les deux moitiés, dans le dépôt : les manifestes RBAC du
 * déployeur (Role, ClusterRole et leurs liaisons) n'ouvrent ni shell ni
 * tunnel, et la commande du runbook reste une lecture, sur l'entrée standard.
 * Le jeton réellement émis est contrôlé à part, contre le cluster, par
 * tools/rotate-deployer-token.sh (`auth can-i create pods/exec` → no).
 */
final class OperatorExecBoundaryTest extends TestCase
{
    /**
     * Ce qu'un humain fait à la main et que la CI ne doit jamais pouvoir faire :
     * shell, attache ou tunnel dans un pod, y compris par joker.
     */
    private const string INTERACTIVE_POD_ACCESS = '#^(\*|pods/\*|(pods|\*)/(exec|attach|portforward))$#';

    private const string LOOKUP_SECTION = 'Pour nommer un compte pendant un incident';

    /**
     * Toutes les règles qui atteignent le ServiceAccount du déployeur : le Role
     * du namespace et le ClusterRole, chacun par sa liaison. Une liaison qui
     * pointerait ailleurs (un ClusterRole `edit` intégré, par exemple) ferait
     * échapper ses droits à la lecture des deux manifestes.
     */
    public function testTheDeployerOpensNeitherAShellNorATunnel(): void
    {
        self::assertSame(['kind' => 'Role', 'name' => 'github-actions-deployer'], $this->roleRef('rolebinding.yaml'));
        self::assertSame(['kind' => 'ClusterRole', 'name' => 'github-actions-deployer'], $this->roleRef('clusterrolebinding.yaml'));

        $granted = [];
        foreach (['role.yaml' => 'Role', 'clusterrole.yaml' => 'ClusterRole'] as $manifest => $kind) {
            foreach ($this->rules($manifest, $kind) as $rule) {
                $groups = $rule['apiGroups'] ?? [];
                self::assertIsArray($groups);
                if (!\in_array('', $groups, true) && !\in_array('*', $groups, true)) {
                    continue;
                }
                $resources = $rule['resources'] ?? [];
                self::assertIsArray($resources);
                foreach ($resources as $resource) {
                    self::assertIsString($resource);
                    $granted[] = $resource;
                }
            }
        }

        self::assertNotEmpty($granted, 'Le déployeur doit porter des règles sur le groupe core.');
        // `pods/exec`, mais aussi les jokers `*`, `pods/*` et `*/exec` que RBAC accepte.
        $interactive = array_values(array_filter(
            $granted,
            static fn (string $resource): bool => 1 === preg_match(self::INTERACTIVE_POD_ACCESS, $resource),
        ));
        self::assertSame([], $interactive);
    }

    /** Une transaction en lecture seule : une écriture par erreur est refusée. */
    public function testTheAccountLookupRunsInAReadOnlyTransaction(): void
    {
        $command = $this->lookupCommand();

        self::assertStringContainsString('PGOPTIONS="-c default_transaction_read_only=on" psql ', $command);
        // -X : aucun ~/.psqlrc du conteneur ; ON_ERROR_STOP : une erreur sort en code non nul.
        self::assertMatchesRegularExpression('/\bpsql -X\b/', $command);
        self::assertStringContainsString('-v ON_ERROR_STOP=1', $command);
    }

    /**
     * Sans `-t`, l'en-tête et « (0 rows) » restent : un namespace erroné ne
     * se confond pas avec un compte supprimé.
     */
    public function testAnEmptyResultStaysVisible(): void
    {
        self::assertDoesNotMatchRegularExpression('/\bpsql\b[^\n]* -[A-Za-z]*t/', $this->lookupCommand());
    }

    /**
     * Un incident vise plus souvent la prod : le namespace est un paramètre
     * obligatoire, jamais une valeur par défaut, et le conteneur est nommé.
     */
    public function testTheAccountLookupTargetsTheNamespaceGivenByTheOperator(): void
    {
        $command = $this->lookupCommand();

        self::assertMatchesRegularExpression('/kubectl -n "\$\{NS:\?[^}]+\}" exec -i deploy\/postgres -c postgres --/', $command);
        self::assertMatchesRegularExpression('/^NS= /m', $command);
        self::assertDoesNotMatchRegularExpression('/-n (preprod|prod)\b/', $command);
    }

    /**
     * La requête arrive par l'entrée standard : ni l'UUID ni le SQL ne figurent
     * dans l'argv de l'exec, donc dans l'URL de la requête à l'API server.
     * L'UUID passe par une variable psql citée et convertie : une apostrophe
     * collée depuis un journal ne devient pas du SQL.
     */
    public function testTheQueryReachesPsqlOnStandardInputAsAQuotedUuid(): void
    {
        $command = $this->lookupCommand();
        $kubectl = strpos($command, 'kubectl ');
        self::assertIsInt($kubectl);

        [$input, $exec] = [substr($command, 0, $kubectl), substr($command, $kubectl)];
        self::assertStringContainsString("\\set id <userId>", $input);
        self::assertStringContainsString("WHERE id = :'id'::uuid;", $input);
        self::assertMatchesRegularExpression('/\|\s*$/', $input, 'La requête est envoyée par un tube.');
        self::assertStringNotContainsString('SELECT', $exec);
        self::assertStringNotContainsString('<userId>', $exec);
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
    private function rules(string $manifest, string $kind): array
    {
        $role = $this->rbac($manifest);
        self::assertSame($kind, $role['kind'] ?? null);

        $rules = $role['rules'] ?? null;
        self::assertIsArray($rules);
        $typed = [];
        foreach ($rules as $rule) {
            self::assertIsArray($rule);
            $typed[] = $rule;
        }

        return $typed;
    }

    /**
     * @return array{kind: mixed, name: mixed}
     */
    private function roleRef(string $manifest): array
    {
        $ref = $this->rbac($manifest)['roleRef'] ?? null;
        self::assertIsArray($ref);

        return ['kind' => $ref['kind'] ?? null, 'name' => $ref['name'] ?? null];
    }

    /**
     * @return array<mixed>
     */
    private function rbac(string $manifest): array
    {
        $path = $this->k8sDir().'/base/github-actions-rbac/'.$manifest;
        self::assertFileIsReadable($path);
        $object = Yaml::parse((string) file_get_contents($path));
        self::assertIsArray($object);

        return $object;
    }

    private function k8sDir(): string
    {
        return \dirname(__DIR__, 5).'/k8s';
    }
}
