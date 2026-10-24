<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Kubernetes;

use App\Mcp\Connection\Application\OAuthEndpoints;
use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * L'Ingress dédié aux deux documents de métadonnées OAuth de la connexion MCP
 * (#647, RFC 9728 et RFC 8414). Ils sont servis par le backend HORS `/api`, là
 * où la norme les place ; l'Ingress principal n'envoie au backend que `/api`
 * et donne tout le reste au frontend, dont le nginx répond 404 sous
 * `/.well-known/` : sans cet objet, un client MCP ne trouverait jamais le
 * serveur d'autorisation.
 *
 * Un objet à part plutôt que deux chemins de plus dans `cp-ghostotof` :
 * ingress-nginx applique les annotations `auth-*` à tous les chemins d'un
 * même objet, et la préprod en pose une (Basic Auth) que ces documents
 * publics ne doivent pas porter — un client MCP ne sait pas répondre à une
 * Basic Auth. Même motif que `k8s/overlays/preprod/ingress-healthz.yaml`.
 *
 * Les chemins sont confrontés à ce que l'application publie
 * ({@see OAuthEndpoints}) et à ce que son routeur sert : renommer une route
 * sans l'Ingress, ou l'inverse, fait rougir ce test au lieu de laisser la
 * préprod répondre 404.
 *
 * Les overlays sont lus tels qu'ils sont écrits ({@see KubernetesManifests}),
 * jamais rendus par kustomize, absent du conteneur de dev.
 */
final class OAuthMetadataIngressTest extends KernelTestCase
{
    private const string MANIFEST = 'ingress-oauth-metadata.yaml';

    private const string INGRESS = 'cp-ghostotof-oauth-metadata';

    /** L'Ingress principal, dont l'hôte de chaque overlay est repris. */
    private const string MAIN_INGRESS = 'cp-ghostotof';

    private const string HOST_PATH = '/spec/rules/0/host';

    public function testTheBaseDeclaresTheIngress(): void
    {
        $kustomization = KubernetesManifests::base()->resource('kustomization.yaml', 'Kustomization');

        self::assertContains(self::MANIFEST, $kustomization['resources'] ?? [], self::MANIFEST.' absent des resources de k8s/base.');
    }

    /**
     * Exactement les deux chemins publiés, en `Exact` (un `Exact` l'emporte
     * sur le préfixe `/` de l'Ingress principal ; un `Prefix` ouvrirait au
     * backend tout ce qui commence par eux), vers le Service du backend.
     */
    public function testItRoutesExactlyThePublishedDocumentsToTheBackend(): void
    {
        $ingress = $this->ingress();
        self::assertSame(self::INGRESS, $ingress['metadata']['name'] ?? null);
        self::assertSame('nginx', $ingress['spec']['ingressClassName'] ?? null);

        $rules = KubernetesManifests::entries($ingress['spec'] ?? [], 'rules');
        self::assertCount(1, $rules, 'Une seule règle, celle de l\'hôte du site.');
        self::assertSame('PLACEHOLDER', $rules[0]['host'] ?? null, 'L\'hôte est posé par chaque overlay.');

        $paths = KubernetesManifests::entries($rules[0]['http'] ?? [], 'paths');
        $routed = [];
        foreach ($paths as $path) {
            self::assertSame('Exact', $path['pathType'] ?? null, \sprintf('%s : pathType Exact attendu.', KubernetesManifests::text($path['path'] ?? null)));
            self::assertSame(['service' => ['name' => 'backend', 'port' => ['number' => 80]]], $path['backend'] ?? null);
            $routed[] = $path['path'] ?? null;
        }
        sort($routed);

        self::assertSame($this->publishedPaths(), $routed);
    }

    /**
     * Le routeur sert ces deux documents à ces chemins-là : l'Ingress et les
     * routes ne peuvent diverger.
     */
    public function testTheRouterServesTheDocumentsAtThoseVeryPaths(): void
    {
        self::bootKernel();
        $routes = self::getContainer()->get('router')->getRouteCollection();

        $served = [];
        foreach (['oauth_protected_resource_metadata', 'oauth_authorization_server_metadata'] as $name) {
            $route = $routes->get($name);
            self::assertNotNull($route, \sprintf('Route %s absente.', $name));
            $served[] = $route->getPath();
        }
        sort($served);

        self::assertSame($this->publishedPaths(), $served);
    }

    /**
     * Aucune annotation du tout, plutôt qu'une liste d'annotations refusées
     * (`auth-*`, cert-manager) qui ne se ferme jamais : ingress-nginx lit
     * des dizaines d'annotations qui changent ce que reçoit le client
     * (`whitelist-source-range`, `server-snippet`, une redirection…). Aucun
     * `tls` non plus : le certificat de l'hôte est celui de l'Ingress
     * principal, nginx-ingress fusionnant les objets d'un même hôte, et un
     * second `tls` ferait gérer le même secret par deux Certificate. La
     * kustomization de la base n'en ajoute aucune à toutes ses ressources.
     */
    public function testItCarriesNoAnnotationNorCertificateOfItsOwn(): void
    {
        $ingress = $this->ingress();

        self::assertArrayNotHasKey('annotations', $ingress['metadata'] ?? [], self::INGRESS.' ne porte aucune annotation.');
        self::assertArrayNotHasKey('tls', $ingress['spec'] ?? []);
        self::assertArrayNotHasKey('commonAnnotations', KubernetesManifests::base()->resource('kustomization.yaml', 'Kustomization'));
    }

    /**
     * Après les patchs de chaque overlay, tels que ce test les lit, l'objet
     * n'a toujours aucune annotation : aucune opération ne touche
     * `/metadata/annotations` (le cas suivant exige d'ailleurs l'hôte seul), et
     * aucun `commonAnnotations` ne la pose sur toutes les ressources de
     * l'overlay — la Basic Auth de la préprod n'est posée que sur
     * `cp-ghostotof`.
     */
    #[DataProvider('overlays')]
    public function testNoOverlayGivesItAnAnnotation(string $overlay): void
    {
        foreach ($this->operationsOn($overlay, self::INGRESS) as $operation) {
            self::assertStringStartsNotWith('/metadata/annotations', KubernetesManifests::text($operation['path'] ?? null), \sprintf('%s : annotation posée sur %s.', $overlay, self::INGRESS));
        }
        self::assertArrayNotHasKey('commonAnnotations', KubernetesManifests::overlay($overlay)->resource('kustomization.yaml', 'Kustomization'), \sprintf('%s : commonAnnotations atteindrait %s.', $overlay, self::INGRESS));
    }

    /**
     * Chaque overlay ne fait que poser l'hôte, celui de l'Ingress principal :
     * aucun autre champ.
     */
    #[DataProvider('overlays')]
    public function testEachOverlaySetsTheMainIngressHostAndNothingElse(string $overlay): void
    {
        $mainHost = $this->hostSetBy($overlay, self::MAIN_INGRESS);

        self::assertSame(
            [['op' => 'replace', 'path' => self::HOST_PATH, 'value' => $mainHost]],
            $this->operationsOn($overlay, self::INGRESS),
        );
    }

    /**
     * Aucun autre patch de l'overlay n'atteint l'objet, ce que les cas
     * précédents ne verraient pas : ils ne lisent que le patch qui le nomme
     * en clair.
     *
     * Les champs d'une cible kustomize sont des expressions régulières
     * (`name: cp-ghostotof.*` vise aussi celui-ci) : `kind` et `name` sont
     * exigés et littéraux. Un sélecteur d'étiquettes ou d'annotations sur une
     * cible Ingress l'atteindrait par ses étiquettes (`part-of`, commune à
     * tout). Un patch sans cible se lit par son propre `metadata.name`, qu'il
     * soit écrit dans un fichier ou en ligne. Les champs hérités
     * `patchesJson6902` et `patchesStrategicMerge` échapperaient à cette
     * lecture de `patches` : refusés.
     */
    #[DataProvider('overlays')]
    public function testNoOtherPatchOfTheOverlayReachesTheIngress(string $overlay): void
    {
        $manifests = KubernetesManifests::overlay($overlay);
        $kustomization = $manifests->resource('kustomization.yaml', 'Kustomization');
        foreach (['patchesJson6902', 'patchesStrategicMerge'] as $legacy) {
            self::assertArrayNotHasKey($legacy, $kustomization, \sprintf('%s : %s, champ hérité, est refusé (patches seulement).', $overlay, $legacy));
        }

        foreach (KubernetesManifests::entries($kustomization, 'patches') as $patch) {
            $target = $patch['target'] ?? null;
            if (null !== $target) {
                self::assertIsArray($target, \sprintf('%s : une cible est une table.', $overlay));
                $this->assertLiteralTarget($overlay, $target);

                continue;
            }
            $documents = isset($patch['path'])
                ? $manifests->documents(KubernetesManifests::text($patch['path']))
                : [Yaml::parse(KubernetesManifests::text($patch['patch'] ?? null))];
            foreach ($documents as $document) {
                self::assertIsArray($document, \sprintf('%s : un patch sans cible est une ressource.', $overlay));
                self::assertNotSame(self::INGRESS, $document['metadata']['name'] ?? null, \sprintf('%s : %s patché sans cible.', $overlay, self::INGRESS));
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function overlays(): iterable
    {
        yield 'preprod' => ['preprod'];
        yield 'prod' => ['prod'];
    }

    /**
     * Une cible kustomize lue comme elle s'applique : `kind` et `name`
     * littéraux (sans quoi ils se lisent comme des expressions régulières),
     * et aucun sélecteur sur une cible Ingress.
     *
     * @param array<mixed> $target
     */
    private function assertLiteralTarget(string $overlay, array $target): void
    {
        $kind = $target['kind'] ?? null;
        $name = $target['name'] ?? null;
        self::assertIsString($kind, \sprintf('%s : une cible sans kind vise toutes les sortes de ressources.', $overlay));
        self::assertMatchesRegularExpression('/\A[A-Za-z]+\z/', $kind, \sprintf('%s : kind de cible non littéral.', $overlay));
        self::assertIsString($name, \sprintf('%s : une cible %s sans name vise toutes les ressources de ce kind.', $overlay, $kind));
        self::assertMatchesRegularExpression('/\A[a-z0-9-]+\z/', $name, \sprintf('%s : name de cible non littéral (%s).', $overlay, $name));
        if ('Ingress' === $kind) {
            self::assertArrayNotHasKey('labelSelector', $target, \sprintf('%s : labelSelector sur une cible Ingress.', $overlay));
            self::assertArrayNotHasKey('annotationSelector', $target, \sprintf('%s : annotationSelector sur une cible Ingress.', $overlay));
        }
    }

    /**
     * Les chemins des deux documents tels que l'application les publie
     * ({@see OAuthEndpoints::protectedResourceMetadataUrl()}, RFC 9728 §3.1, et
     * {@see OAuthEndpoints::authorizationServerMetadataUrl()}, RFC 8414 §3.1),
     * jamais recomposés ici. Triés.
     *
     * @return list<string>
     */
    private function publishedPaths(): array
    {
        $endpoints = new OAuthEndpoints('https://site.example', 'https://site.example');
        $paths = [
            (string) parse_url($endpoints->protectedResourceMetadataUrl(), \PHP_URL_PATH),
            (string) parse_url($endpoints->authorizationServerMetadataUrl(), \PHP_URL_PATH),
        ];
        sort($paths);

        return $paths;
    }

    /**
     * @return array<mixed>
     */
    private function ingress(): array
    {
        return KubernetesManifests::base()->resource(self::MANIFEST, 'Ingress');
    }

    /**
     * La valeur que l'overlay donne à l'hôte de l'Ingress nommé.
     */
    private function hostSetBy(string $overlay, string $ingress): string
    {
        $hosts = array_values(array_filter(
            $this->operationsOn($overlay, $ingress),
            static fn (array $operation): bool => 'replace' === ($operation['op'] ?? null) && self::HOST_PATH === ($operation['path'] ?? null),
        ));
        self::assertCount(1, $hosts, \sprintf('%s : l\'hôte de %s doit être posé une fois.', $overlay, $ingress));
        self::assertIsString($hosts[0]['value'] ?? null);

        return $hosts[0]['value'];
    }

    /**
     * Les opérations JSON 6902 de l'unique patch de l'overlay qui vise cet
     * Ingress par son nom.
     *
     * @return list<array<mixed>>
     */
    private function operationsOn(string $overlay, string $ingress): array
    {
        $kustomization = KubernetesManifests::overlay($overlay)->resource('kustomization.yaml', 'Kustomization');
        $patches = array_values(array_filter(
            KubernetesManifests::entries($kustomization, 'patches'),
            static fn (array $patch): bool => \is_array($patch['target'] ?? null)
                && 'Ingress' === ($patch['target']['kind'] ?? null)
                && $ingress === ($patch['target']['name'] ?? null),
        ));
        self::assertCount(1, $patches, \sprintf('%s : un patch de %s attendu, %d trouvé(s).', $overlay, $ingress, \count($patches)));

        $operations = Yaml::parse(KubernetesManifests::text($patches[0]['patch'] ?? null));
        self::assertIsArray($operations);

        return KubernetesManifests::entries(['operations' => $operations], 'operations');
    }
}
