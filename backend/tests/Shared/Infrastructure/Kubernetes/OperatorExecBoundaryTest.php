<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

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
 *
 * Une seule exception écrite, issue #593 : la procédure des rôles PostgreSQL,
 * qui écrit, passe par un `exec` sur le socket local du pod `postgres`, seul
 * chemin du superutilisateur depuis que le `pg_hba.conf` du dépôt lui ferme le
 * réseau. Elle tient en une ligne de sa section, que ce test exempte et dont
 * {@see PostgresRolesProcedureTest} pince la forme.
 */
final class OperatorExecBoundaryTest extends TestCase
{
    /**
     * Ce qu'un humain fait à la main et que la CI ne doit jamais pouvoir faire :
     * shell, attache ou tunnel dans un pod, y compris par joker.
     */
    private const string INTERACTIVE_POD_ACCESS = '#^(\*|pods/\*|(pods|\*)/(exec|attach|portforward))$#';

    private const string LOOKUP_SECTION = 'Pour nommer un compte pendant un incident';

    private const string FAILED_READ_SECTION = 'Pour lire `failed`';

    /**
     * La seule commande console qu'un `exec` du runbook peut lancer : une
     * lecture (issue #521). Tout le reste — rejeu, suppression, signal
     * d'arrêt, purge, abréviations comprises (`m:f:rem`) — est un Job.
     */
    private const string READ_ONLY_CONSOLE_COMMAND = 'messenger:failed:show';

    /**
     * Le rôle PostgreSQL des `psql` du runbook (issue #575) : `pg_read_all_data`
     * et rien d'autre, sans mot de passe, joignable par le seul socket local du
     * pod. La lecture seule devient une frontière, là où
     * `default_transaction_read_only` sous le superutilisateur n'était qu'un
     * garde-fou qu'un `SET` levait. Créé par la procédure des rôles du runbook.
     */
    private const string READER_ROLE = 'cpg_reader';

    /** La section de l'unique `psql` qui écrit (issue #593). */
    private const string ROLES_SECTION = 'Pour créer ou rejouer les rôles';

    /**
     * Toutes les règles qui atteignent le ServiceAccount du déployeur : le Role
     * du namespace et le ClusterRole, chacun par sa liaison. Une liaison qui
     * pointerait ailleurs (un ClusterRole `edit` intégré, par exemple) ferait
     * échapper ses droits à la lecture des deux manifestes.
     */
    public function testTheDeployerOpensNeitherAShellNorATunnel(): void
    {
        self::assertSame(['kind' => 'Role', 'name' => 'github-actions-deployer'], $this->roleRef('rolebinding.yaml', 'RoleBinding'));
        self::assertSame(['kind' => 'ClusterRole', 'name' => 'github-actions-deployer'], $this->roleRef('clusterrolebinding.yaml', 'ClusterRoleBinding'));

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

        self::assertStringContainsString('PGOPTIONS="-c default_transaction_read_only=on -c lock_timeout=5s" psql ', $command);
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
     * Chaque `psql` que le runbook lance par `exec` (recherche d'un compte,
     * keepalives, comptage des hachages par format — issue #590, contrôle des
     * rôles — issue #575) est une lecture : sous le rôle lecteur, session en
     * lecture seule et bornée, sans ~/.psqlrc, erreur en code non nul, dans le
     * namespace que l'opérateur donne, et la requête sur l'entrée standard
     * (tube ou heredoc), jamais dans l'argv de l'exec.
     */
    public function testEveryRunbookPsqlIsAReadOnlySessionInTheNamespaceGivenByTheOperator(): void
    {
        $roles = $this->execLines($this->bashBlockAfter(self::ROLES_SECTION));
        $calls = array_values(array_filter(
            $this->runbookExecLines(),
            static fn (string $line): bool => 1 === preg_match('/\bpsql\b/', $line) && !\in_array($line, $roles, true),
        ));
        self::assertGreaterThanOrEqual(4, \count($calls), 'Le runbook a perdu un de ses psql en lecture : relire ce test.');

        foreach ($calls as $call) {
            $exec = substr($call, (int) strpos($call, 'kubectl '));
            self::assertMatchesRegularExpression('/kubectl -n "\$\{NS:\?[^}]+\}" exec -i deploy\/postgres -c postgres --/', $exec, $call);
            self::assertStringContainsString('PGOPTIONS="-c default_transaction_read_only=on -c lock_timeout=5s" psql ', $exec, $call);
            self::assertMatchesRegularExpression('/\bpsql -X\b/', $exec, $call);
            self::assertStringContainsString('-v ON_ERROR_STOP=1', $exec, $call);
            self::assertStringContainsString('-U '.self::READER_ROLE.' ', $exec, 'Un psql du runbook ne tourne que sous le rôle lecteur : '.$call);
            self::assertStringNotContainsString('POSTGRES_USER', $exec, 'Jamais le superutilisateur de l\'image : '.$call);
            self::assertDoesNotMatchRegularExpression('/-n (preprod|prod)\b/', $exec, $call);
            self::assertDoesNotMatchRegularExpression('/\b(SELECT|SHOW|UPDATE|DELETE|INSERT)\b/', $exec, 'Requête dans l\'argv de l\'exec : '.$call);
        }
    }

    /**
     * L'exception de #593 ne s'étend pas : sa section ne porte qu'un `exec`, et
     * c'est un `psql`. Une deuxième commande écrite là échapperait au contrôle
     * des lectures ci-dessus.
     */
    public function testTheRolesProcedureIsTheOnlyExemptedExec(): void
    {
        $roles = $this->execLines($this->bashBlockAfter(self::ROLES_SECTION));

        self::assertCount(1, $roles, 'La section des rôles ne porte qu\'un exec.');
        self::assertMatchesRegularExpression('/\bpsql\b/', $roles[0]);
    }

    /**
     * Issue #521 : rejouer, supprimer un message de `failed` ou relancer le
     * signal d'arrêt des workers a un effet de bord, donc passe par un Job
     * (`k8s/base/messenger-*-job.yaml`). Un `kubectl exec` du runbook qui lance
     * la console n'y lance que la lecture de `failed` — une liste blanche : une
     * abréviation que Symfony accepte (`m:f:rem`) ou une autre commande qui
     * écrit (`cache:pool:clear`) ne passe pas.
     */
    public function testNoRunbookExecRunsAConsoleCommandOtherThanTheFailedRead(): void
    {
        $offending = [];
        foreach ($this->runbookExecLines() as $line) {
            preg_match_all('#bin/console\s+(\S+)#', $line, $commands);
            foreach ($commands[1] as $command) {
                if (self::READ_ONLY_CONSOLE_COMMAND !== $command) {
                    $offending[] = $line;
                }
            }
            if (str_contains($line, 'console') && 0 === \count($commands[1])) {
                $offending[] = $line;
            }
        }

        self::assertSame([], $offending);
    }

    /**
     * La lecture de `failed` reste un exec humain : en `-vv`, la sortie porte
     * le message lui-même (nom, adresse, texte d'un visiteur), qu'un Job
     * écrirait dans les journaux de son pod. Chaque appel ne fait que lire —
     * `messenger:failed:show`, dans une session PostgreSQL en lecture seule,
     * qu'une écriture par erreur ferait échouer (mesuré pour #521 : un
     * `failed:remove` y sort en SQLSTATE 25006) — et vise le namespace donné.
     */
    public function testTheFailedTransportIsReadOnlyThroughAReadOnlySession(): void
    {
        $block = $this->bashBlockAfter(self::FAILED_READ_SECTION);
        self::assertMatchesRegularExpression('/^NS= /m', $block);

        $calls = array_values(array_filter(
            $this->execLines($block),
            static fn (string $line): bool => str_contains($line, 'bin/console'),
        ));
        self::assertNotEmpty($calls, 'La section « lire failed » n\'a plus de commande.');

        foreach ($calls as $call) {
            self::assertMatchesRegularExpression('/kubectl -n "\$\{NS:\?[^}]+\}" exec deploy\/backend -c php-fpm --/', $call);
            self::assertStringContainsString("env PGOPTIONS='-c default_transaction_read_only=on -c lock_timeout=5s' php bin/console messenger:failed:show ", $call);
            self::assertDoesNotMatchRegularExpression('/-n (preprod|prod)\b/', $call);
        }
    }

    /**
     * Le bloc `bash` qui suit la phrase d'introduction de la section.
     */
    private function lookupCommand(): string
    {
        return $this->bashBlockAfter(self::LOOKUP_SECTION);
    }

    private function bashBlockAfter(string $section): string
    {
        $readme = $this->readme();
        $start = strpos($readme, $section);
        self::assertIsInt($start, \sprintf('La section « %s » a disparu du runbook.', $section));

        self::assertSame(1, preg_match('/```bash\n(.*?)```/s', substr($readme, $start), $block));

        return $block[1];
    }

    /**
     * Les commandes `kubectl … exec` de tout le runbook.
     *
     * @return list<string>
     */
    private function runbookExecLines(): array
    {
        return $this->execLines($this->readme());
    }

    /**
     * Les lignes d'un texte qui ouvrent un `kubectl exec`, continuations
     * (`\` en fin de ligne) rejointes.
     *
     * @return list<string>
     */
    private function execLines(string $text): array
    {
        $joined = preg_replace('/\\\\\n\s*/', ' ', $text);
        self::assertIsString($joined);

        return array_values(array_filter(
            explode("\n", $joined),
            static fn (string $line): bool => 1 === preg_match('/\bkubectl\b.*\bexec\b/', $line),
        ));
    }

    /**
     * Le runbook, à côté de la base.
     */
    private function readme(): string
    {
        return (string) file_get_contents(\dirname(KubernetesManifests::base()->directory()).'/README.md');
    }

    /**
     * @return list<array<mixed>>
     */
    private function rules(string $manifest, string $kind): array
    {
        $role = $this->rbac($manifest, $kind);

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
    private function roleRef(string $manifest, string $kind): array
    {
        $ref = $this->rbac($manifest, $kind)['roleRef'] ?? null;
        self::assertIsArray($ref);

        return ['kind' => $ref['kind'] ?? null, 'name' => $ref['name'] ?? null];
    }

    /**
     * @return array<mixed>
     */
    private function rbac(string $manifest, string $kind): array
    {
        return KubernetesManifests::base()->resource('github-actions-rbac/'.$manifest, $kind);
    }
}
