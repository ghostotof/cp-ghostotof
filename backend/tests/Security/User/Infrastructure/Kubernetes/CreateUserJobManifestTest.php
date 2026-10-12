<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * Revue de #386 : l'événement `user-created` part sur le stderr du processus
 * de la commande. Lancée par `kubectl exec`, c'est le terminal de l'opérateur,
 * pas le flux du conteneur que lit `kubectl logs` : la création la plus
 * sensible, celle d'un ROLE_SUPER, ne laissait aucune trace côté cluster.
 * `k8s/base/create-user-job.yaml` lance la commande dans son propre pod, dont
 * les journaux sont ceux du conteneur.
 *
 * Ce test pince ce qui fait de ce Job une voie sûre : le mot de passe ne
 * passe jamais par l'argv (lu sur l'entrée standard depuis un Secret monté en
 * lecture seule), pas de reprise, et le même durcissement que le Job de
 * migration.
 */
final class CreateUserJobManifestTest extends TestCase
{
    private const string MANIFEST = 'create-user-job.yaml';
    private const string SECRET_NAME = 'backend-create-user-password';
    private const string SECRET_MOUNT = '/run/secrets/create-user';

    public function testTheJobReadsThePasswordFromTheMountedSecretOnStandardInput(): void
    {
        $command = $this->commandLine();

        self::assertStringContainsString('app:user:create', $command);
        self::assertStringContainsString('--password-stdin', $command);
        self::assertStringContainsString('< '.self::SECRET_MOUNT.'/password', $command);
        self::assertDoesNotMatchRegularExpression('/--password(?!-stdin)/', $command, 'Le mot de passe ne passe jamais par l\'argv.');
    }

    /** ADR 0001 : en production, la CLI ne sert qu'à amorcer un ROLE_SUPER. */
    public function testTheJobCreatesASuperAdministrator(): void
    {
        self::assertStringContainsString('--role=ROLE_SUPER', $this->commandLine());
    }

    public function testTheSecretIsMountedReadOnlyAndReadableByTheGroupOnly(): void
    {
        $spec = $this->podSpec();
        $volume = $this->firstWith($spec['volumes'] ?? null, 'name', 'create-user-password');
        self::assertNotNull($volume);
        self::assertSame(self::SECRET_NAME, $volume['secret']['secretName'] ?? null);
        // 0440 : le groupe du fsGroup lit, personne n'écrit.
        self::assertSame(0o440, $volume['secret']['defaultMode'] ?? null);

        $mount = $this->firstWith($this->container()['volumeMounts'] ?? null, 'name', 'create-user-password');
        self::assertNotNull($mount);
        self::assertSame(self::SECRET_MOUNT, $mount['mountPath'] ?? null);
        self::assertTrue($mount['readOnly'] ?? false);
    }

    /** Une création qui échoue (nom déjà pris…) demande un humain, pas une reprise. */
    public function testAFailedCreationIsNeverRetried(): void
    {
        self::assertSame(0, $this->job()['spec']['backoffLimit'] ?? null);
    }

    /**
     * Revue de #488 : un pod qui ne démarre jamais (Secret supprimé avant son
     * montage, image introuvable) laissait le Job actif sans fin — et
     * `ttlSecondsAfterFinished` ne compte qu'à partir de la fin, si bien que
     * le nom du compte restait dans sa spec. Le Job porte donc un délai, 360 s.
     * Sa place face à l'attente de la procédure (`tools/run-job.sh … 300`,
     * k8s/README.md) suit la convention de tous les Jobs (issue #535) : au-dessus,
     * pour que les journaux d'un pod bloqué soient lus avant sa suppression ;
     * `tools/tests/run-job.test.sh` la vérifie, seul garde de cette relation.
     */
    public function testTheJobIsBounded(): void
    {
        self::assertSame(360, $this->job()['spec']['activeDeadlineSeconds'] ?? null);
    }

    public function testThePodIsHardenedLikeTheMigrationJob(): void
    {
        $spec = $this->podSpec();
        self::assertFalse($spec['automountServiceAccountToken'] ?? null);
        self::assertSame('Never', $spec['restartPolicy'] ?? null);

        $securityContext = $this->container()['securityContext'] ?? null;
        self::assertSame([
            'runAsNonRoot' => true,
            'runAsUser' => 10001,
            'readOnlyRootFilesystem' => true,
            'allowPrivilegeEscalation' => false,
            'capabilities' => ['drop' => ['ALL']],
        ], $securityContext);
    }

    /**
     * Appliqué à la main par tools/run-job.sh : seules ces deux variables sont
     * substituées, une autre resterait littérale. Aucun nom de compte réel
     * dans le dépôt (Goal #9) : le nom arrive au moment de l'apply.
     */
    public function testOnlyTheImageAndTheUsernameAreSubstituted(): void
    {
        preg_match_all('/\$\{([A-Z_]+)\}/', $this->source(), $matches);

        self::assertSame(['BACKEND_IMAGE', 'CPG_USERNAME'], array_values(array_unique($matches[1])));
    }

    /**
     * `envsubst` remplace aussi la forme `$VAR`, et, sans liste de variables,
     * toute variable inconnue de l'opérateur par une chaîne vide. Le script
     * lit donc le nom sous une autre variable que le placeholder, que la
     * procédure ne substitue pas (tools/run-job.sh ne passe à envsubst que
     * `${BACKEND_IMAGE}` et `${CPG_USERNAME}`) :
     * sans quoi le nom serait inliné dans le script shell, ou effacé.
     */
    public function testTheShellScriptReadsTheUsernameFromAVariableEnvsubstLeavesAlone(): void
    {
        $command = $this->commandLine();
        self::assertStringContainsString('--username="$NEW_ACCOUNT_USERNAME"', $command);
        self::assertStringNotContainsString('CPG_USERNAME', $command);

        $variable = $this->firstWith($this->container()['env'] ?? null, 'name', 'NEW_ACCOUNT_USERNAME');
        self::assertNotNull($variable);
        self::assertSame('${CPG_USERNAME}', $variable['value'] ?? null);
    }

    /** Hors de `kustomization.yaml` : un `apply -k` ne doit jamais créer de compte. */
    public function testTheJobIsNotPartOfTheKustomization(): void
    {
        self::assertStringNotContainsString(self::MANIFEST, KubernetesManifests::base()->source('kustomization.yaml'));
    }

    private function commandLine(): string
    {
        $command = $this->container()['command'] ?? null;
        self::assertIsArray($command);

        return implode(' ', array_map(static fn (mixed $part): string => \is_string($part) ? $part : '', $command));
    }

    /**
     * @return array<mixed>
     */
    private function container(): array
    {
        return KubernetesManifests::base()->container(self::MANIFEST, 'Job', 'create-user');
    }

    /**
     * @return array<mixed>
     */
    private function podSpec(): array
    {
        return KubernetesManifests::base()->podSpec(self::MANIFEST, 'Job');
    }

    /**
     * @return array<mixed>
     */
    private function job(): array
    {
        return KubernetesManifests::base()->resource(self::MANIFEST, 'Job');
    }

    private function source(): string
    {
        return KubernetesManifests::base()->source(self::MANIFEST);
    }

    /**
     * @return array<mixed>|null
     */
    private function firstWith(mixed $list, string $key, string $value): ?array
    {
        foreach (\is_array($list) ? $list : [] as $item) {
            if (\is_array($item) && $value === ($item[$key] ?? null)) {
                return $item;
            }
        }

        return null;
    }
}
