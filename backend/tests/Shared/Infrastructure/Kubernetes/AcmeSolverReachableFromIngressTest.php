<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * L'ingress-nginx joint les pods solveurs HTTP-01 de cert-manager (2026-10-26).
 *
 * `default-deny-ingress` (`k8s/base/network-policies.yaml`, 2026-09-02) refuse
 * toute entrée à tout pod du namespace, et `allow-web-from-ingress-nginx`
 * n'ouvre que le frontend et le backend. Le solveur que cert-manager crée
 * dans le namespace du certificat le temps d'un challenge (label
 * `acme.cert-manager.io/http01-solver: "true"`, port 8089) n'était donc
 * joignable par personne : le premier renouvellement depuis ces règles,
 * le 2026-10-09, est resté bloqué 17 jours sur trois certificats (préprod,
 * prod, www), l'ingress répondant 504 après trois tentatives de connexion de
 * 5 s (mesuré : 547 `upstream timed out` vers les trois solveurs en une
 * heure). Let's Encrypt aurait buté sur le même 504.
 *
 * Le test exige la règle qui l'ouvre, et rien de plus : un sélecteur sur le
 * seul label des solveurs, une source, le namespace `ingress-nginx`, un port.
 */
final class AcmeSolverReachableFromIngressTest extends TestCase
{
    private const string MANIFEST = 'network-policies.yaml';

    private const string POLICY = 'allow-acme-solver-from-ingress-nginx';

    /** Le label que cert-manager pose sur chaque pod solveur HTTP-01. */
    private const array SOLVER_LABELS = ['acme.cert-manager.io/http01-solver' => 'true'];

    /** Le port d'écoute du solveur de cert-manager (`acmesolver`, défaut 8089). */
    private const int SOLVER_PORT = 8089;

    public function testAPolicyLetsTheIngressReachTheSolverPods(): void
    {
        $spec = $this->policySpec();

        self::assertSame(['matchLabels' => self::SOLVER_LABELS], $spec['podSelector'] ?? null, self::POLICY.' : le sélecteur vise les seuls pods solveurs, par leur label.');
        self::assertSame(['Ingress'], $spec['policyTypes'] ?? null);
        self::assertSame(
            [[
                'from' => [['namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => 'ingress-nginx']]]],
                'ports' => [['protocol' => 'TCP', 'port' => self::SOLVER_PORT]],
            ]],
            $spec['ingress'] ?? null,
            self::POLICY.' : une seule règle, depuis ingress-nginx, sur le port du solveur.',
        );
    }

    /**
     * La même source que le trafic web : un ingress déplacé dans un autre
     * namespace, corrigé pour le frontend et le backend seulement, laisserait
     * les solveurs injoignables sans que rien d'autre ne le dise.
     */
    public function testTheSolverPolicyAdmitsTheSameSourceAsTheWebTraffic(): void
    {
        $web = $this->policies()['allow-web-from-ingress-nginx'] ?? null;
        self::assertIsArray($web, 'allow-web-from-ingress-nginx manquante.');

        self::assertSame($web['ingress'][0]['from'] ?? null, $this->policySpec()['ingress'][0]['from'] ?? null, self::POLICY.' et allow-web-from-ingress-nginx doivent admettre la même source.');
    }

    /**
     * La règle qui la rend nécessaire est toujours là : sans elle, ce test
     * garderait une ouverture devenue sans objet.
     */
    public function testTheNamespaceStillDeniesIngressByDefault(): void
    {
        $deny = $this->policies()['default-deny-ingress'] ?? null;

        self::assertIsArray($deny, 'default-deny-ingress manquante.');
        self::assertSame([], $deny['podSelector'] ?? null);
        self::assertSame(['Ingress'], $deny['policyTypes'] ?? null);
    }

    /**
     * @return array<mixed>
     */
    private function policySpec(): array
    {
        $policy = $this->policies()[self::POLICY] ?? null;
        self::assertIsArray($policy, \sprintf('%s : NetworkPolicy %s manquante.', self::MANIFEST, self::POLICY));

        return $policy;
    }

    /**
     * Le `spec` de chaque NetworkPolicy du manifeste, par nom. Un nom écrit
     * deux fois est refusé, comme le fait {@see KubernetesManifests} : le
     * cluster appliquerait les deux documents l'un après l'autre, et un
     * index qui garde le dernier jugerait un document que le cluster ne
     * garde pas forcément.
     *
     * @return array<string, array<mixed>>
     */
    private function policies(): array
    {
        $policies = [];
        foreach (KubernetesManifests::base()->documents(self::MANIFEST) as $resource) {
            self::assertSame('NetworkPolicy', $resource['kind'] ?? null, self::MANIFEST.' ne contient que des NetworkPolicy.');
            $name = $resource['metadata']['name'] ?? null;
            $spec = $resource['spec'] ?? null;
            self::assertIsString($name);
            self::assertIsArray($spec);
            self::assertArrayNotHasKey($name, $policies, \sprintf('%s : NetworkPolicy %s écrite deux fois.', self::MANIFEST, $name));
            $policies[$name] = $spec;
        }

        return $policies;
    }
}
