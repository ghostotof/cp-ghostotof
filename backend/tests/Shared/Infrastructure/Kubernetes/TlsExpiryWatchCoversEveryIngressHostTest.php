<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use App\Tests\Support\RepositoryFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Le contrôle quotidien des certificats couvre chaque hôte TLS des Ingress,
 * ni plus ni moins (issue #719).
 *
 * Le renouvellement Let's Encrypt est resté bloqué 17 jours sans signal parce
 * que rien ne regardait les certificats entre deux releases. Le workflow
 * `tls-expiry.yml` les regarde chaque jour, mais par une liste d'hôtes écrite
 * à la main : un nouvel Ingress portant son propre bloc `tls` (un
 * sous-domaine, un autre Secret) renouvellerait en silence, la même faute
 * que l'incident. Le test lit les deux formes sous lesquelles un hôte TLS
 * entre dans les manifests — le `spec.tls[].hosts` d'un Ingress écrit dans
 * un répertoire, et le patch JSON 6902 qu'un `kustomization.yaml` d'overlay
 * applique à l'Ingress de base — et exige le même ensemble que les arguments
 * du script dans le workflow.
 *
 * Il refuse ce qu'il ne sait pas lire plutôt que de l'ignorer : un patch qui
 * touche `/spec/tls` sous une autre forme, ou un patch stratégique fourni par
 * fichier (`path:`) ciblant un Ingress, ferait passer un hôte pour absent.
 */
final class TlsExpiryWatchCoversEveryIngressHostTest extends TestCase
{
    private const string WORKFLOW = '.github/workflows/tls-expiry.yml';

    private const string SCRIPT = 'tools/check-tls-expiry.sh';

    /** Les overlays déployés, en plus de `k8s/base`. */
    private const array OVERLAYS = ['prod', 'preprod'];

    /** `/spec/tls/<n>/hosts` : la liste d'hôtes d'une entrée `tls`, remplacée ou ajoutée. */
    private const string TLS_HOSTS_PATH = '#^/spec/tls/\d+/hosts\z#';

    public function testTheDailyCheckCoversExactlyTheIngressTlsHosts(): void
    {
        self::assertSame(
            $this->ingressTlsHosts(),
            $this->checkedHosts(),
            \sprintf("Les hôtes de %s doivent être exactement les hôtes TLS des Ingress de k8s/ : un hôte absent n'est contrôlé par personne.", self::WORKFLOW),
        );
    }

    /**
     * Les deux formes de déclaration sont lues : sans ce témoin, un recensement
     * qui ne trouverait rien, face à un workflow vidé, passerait.
     */
    public function testTheCensusReadsBothFormsOfDeclaration(): void
    {
        $hosts = $this->ingressTlsHosts();

        // L'Ingress de redirection, écrit en entier dans l'overlay prod.
        self::assertContains('www.cp-ghostotof.com', $hosts);
        // L'Ingress de base, dont chaque overlay patche l'hôte.
        self::assertContains('cp-ghostotof.com', $hosts);
        self::assertContains('preprod.cp-ghostotof.com', $hosts);
    }

    /**
     * La syntaxe `NOM@ADRESSE:PORT` du script sert aux tests hors ligne : dans
     * le workflow, elle vérifierait un nom en se connectant ailleurs.
     */
    public function testTheWorkflowConnectsToEachHostItself(): void
    {
        foreach ($this->checkedHosts() as $host) {
            self::assertMatchesRegularExpression('#^[a-z0-9.-]+\z#', $host, \sprintf('%s : « %s » n\'est pas un simple nom d\'hôte.', self::WORKFLOW, $host));
        }
    }

    /**
     * @return list<string> triés, sans doublon
     */
    private function ingressTlsHosts(): array
    {
        $hosts = [];
        $directories = [KubernetesManifests::base()];
        foreach (self::OVERLAYS as $overlay) {
            $directories[] = KubernetesManifests::overlay($overlay);
        }
        foreach ($directories as $manifests) {
            foreach ($this->manifestFiles($manifests) as $file) {
                foreach ($manifests->documents($file) as $resource) {
                    $where = \sprintf('%s/%s', basename($manifests->directory()), $file);
                    if ('Ingress' === ($resource['kind'] ?? null)) {
                        $hosts = [...$hosts, ...$this->hostsOfIngress($resource, $where)];
                    }
                    if ('Kustomization' === ($resource['kind'] ?? null)) {
                        $hosts = [...$hosts, ...$this->hostsOfPatches($resource, $where)];
                    }
                }
            }
        }

        return $this->normalized($hosts);
    }

    /**
     * @param array<mixed> $ingress
     *
     * @return list<string>
     */
    private function hostsOfIngress(array $ingress, string $where): array
    {
        $spec = $ingress['spec'] ?? [];
        self::assertIsArray($spec, $where.' : spec illisible.');

        $hosts = [];
        foreach (KubernetesManifests::entries($spec, 'tls') as $tls) {
            $hosts = [...$hosts, ...$this->hostList($tls['hosts'] ?? [], $where.', spec.tls[].hosts')];
        }

        return $hosts;
    }

    /**
     * Les hôtes qu'un overlay pose sur un Ingress par patch JSON 6902.
     *
     * @param array<mixed> $kustomization
     *
     * @return list<string>
     */
    private function hostsOfPatches(array $kustomization, string $where): array
    {
        $hosts = [];
        foreach (KubernetesManifests::entries($kustomization, 'patches') as $patch) {
            $target = $patch['target'] ?? [];
            self::assertIsArray($target);
            $inline = $patch['patch'] ?? null;
            if (null === $inline) {
                // Un patch par fichier : le fichier est lu comme manifeste
                // du répertoire, sauf s'il cible un Ingress par `target`,
                // forme que ce test ne sait pas lire.
                self::assertNotSame('Ingress', $target['kind'] ?? null, $where.' : patch d\'Ingress par fichier, à apprendre à ce test.');
                continue;
            }
            if ('Ingress' !== ($target['kind'] ?? null)) {
                continue;
            }
            self::assertIsString($inline, $where.' : patch illisible.');
            $operations = Yaml::parse($inline);
            self::assertIsArray($operations, $where.' : un patch JSON 6902 est une liste d\'opérations.');
            foreach ($operations as $operation) {
                self::assertIsArray($operation);
                $path = $operation['path'] ?? '';
                self::assertIsString($path);
                if (!str_starts_with($path, '/spec/tls')) {
                    continue;
                }
                if (1 !== preg_match(self::TLS_HOSTS_PATH, $path)) {
                    // `secretName` ne nomme aucun hôte ; toute autre forme
                    // (un `tls` entier, un hôte par index) en nommerait un.
                    self::assertMatchesRegularExpression('#^/spec/tls/\d+/secretName\z#', $path, $where.' : opération sur '.$path.', à apprendre à ce test.');
                    continue;
                }
                self::assertContains($operation['op'] ?? null, ['replace', 'add'], $where.' : '.$path);
                $hosts = [...$hosts, ...$this->hostList($operation['value'] ?? null, $where.', '.$path)];
            }
        }

        return $hosts;
    }

    /**
     * @return list<string>
     */
    private function hostList(mixed $value, string $where): array
    {
        self::assertIsArray($value, $where.' : une liste d\'hôtes est attendue.');
        $hosts = [];
        foreach ($value as $host) {
            self::assertIsString($host, $where.' : un hôte est une chaîne.');
            $hosts[] = $host;
        }

        return $hosts;
    }

    /**
     * Les arguments du script, lus sur l'unique étape qui l'appelle.
     *
     * @return list<string> triés, sans doublon
     */
    private function checkedHosts(): array
    {
        $workflow = Yaml::parse(RepositoryFiles::read(RepositoryFiles::repository(self::WORKFLOW)));
        self::assertIsArray($workflow);
        $jobs = $workflow['jobs'] ?? null;
        self::assertIsArray($jobs, self::WORKFLOW.' : aucun job.');

        $calls = [];
        foreach ($jobs as $job) {
            self::assertIsArray($job);
            foreach (KubernetesManifests::entries($job, 'steps') as $step) {
                $run = trim(KubernetesManifests::text($step['run'] ?? null));
                if (str_starts_with($run, self::SCRIPT)) {
                    $calls[] = $run;
                }
            }
        }
        self::assertCount(1, $calls, \sprintf('%s : une seule étape appelle %s.', self::WORKFLOW, self::SCRIPT));

        $words = preg_split('#\s+#', $calls[0]);
        self::assertIsArray($words);
        self::assertSame(self::SCRIPT, array_shift($words), self::WORKFLOW.' : l\'appel tient sur une ligne, sans continuation.');

        return $this->normalized($words);
    }

    /**
     * Les manifestes YAML d'un répertoire, sans ses sous-répertoires.
     *
     * @return list<string>
     */
    private function manifestFiles(KubernetesManifests $manifests): array
    {
        $files = glob($manifests->directory().'/*.{yaml,yml}', \GLOB_BRACE);
        self::assertIsArray($files);
        self::assertNotSame([], $files, $manifests->directory().' : aucun manifeste.');

        return array_map(basename(...), $files);
    }

    /**
     * @param list<string> $hosts
     *
     * @return list<string>
     */
    private function normalized(array $hosts): array
    {
        $hosts = array_values(array_unique($hosts));
        sort($hosts);

        return $hosts;
    }
}
