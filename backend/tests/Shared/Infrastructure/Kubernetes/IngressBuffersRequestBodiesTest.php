<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * L'ingress-nginx placé devant le sidecar lit tout le corps d'une requête
 * avant de joindre le pod, et refuse en 413 un corps au-delà de la borne du
 * sidecar (issue #712, suivi de #702).
 *
 * L'acceptation de #702 (un multipart n'est compté par aucune zone du sidecar,
 * `.claude/rules/deploiement-nginx.md`, « Multipart bodies never reach PHP »)
 * s'appuie en partie sur ces deux réglages : un corps lent n'arrive jamais
 * lentement au sidecar, et un corps démesuré ne l'atteint pas. Ce sont les
 * valeurs par défaut du contrôleur, relevées sur le cluster le 2026-10-26 (la
 * ConfigMap du contrôleur ne les posait pas, aucun Ingress ne les surchargeait
 * par annotation).
 *
 * Une valeur par défaut n'est écrite nulle part : un changement de chart, une
 * valeur posée à la main (que `helm upgrade --reuse-values` conserve) ou une
 * annotation d'Ingress ajoutée pour un futur flux les changerait sans rien
 * faire virer au rouge. D'où l'épinglage dans les valeurs Helm, et ce test,
 * qui garde ce que le dépôt contrôle. Il ne voit pas le cluster : un réglage
 * posé à la main hors du dépôt reste l'affaire de la vérification en lecture
 * seule de `k8s/README.md` (prérequis n°2).
 */
final class IngressBuffersRequestBodiesTest extends TestCase
{
    private const string VALUES = 'ingress-nginx-values.yaml';

    /**
     * Les clés de la ConfigMap du contrôleur, sous `controller.config`, que
     * #712 épingle, et leur valeur telle qu'elle doit arriver au contrôleur.
     */
    private const array PINNED = [
        'proxy-request-buffering' => 'on',
        'proxy-body-size' => '1m',
    ];

    /**
     * Les autres clés de `controller.config`, chacune relue. La liste est
     * fermée : une clé de la ConfigMap s'applique à tous les Ingress, et
     * certaines rouvriraient ce que #712 ferme (`http-snippet`,
     * `server-snippet`, `location-snippet`, une borne de corps à un autre
     * niveau…).
     */
    private const array REVIEWED_CONFIG_KEYS = [
        'use-proxy-protocol' => 'adresse réelle du visiteur à travers le LB Scaleway (audit 2026-09-16, A25)',
    ];

    /**
     * Ce qui ne doit être écrit nulle part sous `k8s/` hors du fichier des
     * valeurs, commentaires compris (#616), documentation exceptée :
     *
     * - les deux clés épinglées, sous leur forme de ConfigMap ou d'annotation
     *   d'Ingress (`nginx.ingress.kubernetes.io/proxy-body-size`), qui
     *   remplace la ConfigMap pour son Ingress ;
     * - la directive nginx `proxy_request_buffering`, qu'un snippet porterait ;
     * - toute annotation de snippet (`nginx.ingress.kubernetes.io/configuration-snippet`…),
     *   par sa clé : un commentaire qui la nomme sans préfixe reste libre ;
     * - le réglage qui autorise ces annotations, que seul le fichier des
     *   valeurs pose.
     *
     * `client_max_body_size` n'y est pas : le sidecar le pose légitimement,
     * et {@see self::testTheIngressBoundEqualsTheSidecarServerBound()} le lit.
     * Fragments de regex (délimiteur `#`), comparés sans casse.
     */
    private const array REFUSED_OUTSIDE_VALUES = [
        'proxy-request-buffering',
        'proxy-body-size',
        'proxy_request_buffering',
        'nginx\.ingress\.kubernetes\.io/[a-z0-9-]*snippet',
        'allow-snippet-annotations',
        'allowSnippetAnnotations',
    ];

    public function testTheHelmValuesPinBothSettings(): void
    {
        $config = $this->controllerConfig();

        foreach (self::PINNED as $key => $expected) {
            self::assertSame($expected, $config[$key] ?? null, \sprintf('%s doit épingler controller.config.%s à « %s » (#712).', self::VALUES, $key, $expected));
        }
    }

    /**
     * Écrites entre guillemets, à l'octet près. Helm lit ses valeurs en YAML
     * 1.1, où un `on` nu est le booléen vrai, que le gabarit de la ConfigMap
     * du chart rend `"true"` (mesuré avec `helm template`, chart 4.15.1) ; le
     * composant Yaml de Symfony, en YAML 1.2, le lit comme la chaîne `on`, et
     * le test précédent resterait vert.
     */
    public function testThePinnedValuesAreQuotedAsHelmMustReadThem(): void
    {
        $source = KubernetesManifests::tree()->source(self::VALUES);

        foreach (self::PINNED as $key => $expected) {
            self::assertSame(
                1,
                preg_match_all('/^[ \t]+'.preg_quote($key, '/').': "'.preg_quote($expected, '/').'"[ \t]*$/m', $source),
                \sprintf('%s doit écrire %s: "%s", guillemets compris : Helm lit un scalaire nu en YAML 1.1 (#712).', self::VALUES, $key, $expected),
            );
        }
    }

    public function testTheControllerConfigHoldsOnlyReviewedKeys(): void
    {
        self::assertEqualsCanonicalizing(
            [...array_keys(self::PINNED), ...array_keys(self::REVIEWED_CONFIG_KEYS)],
            array_keys($this->controllerConfig()),
            \sprintf('controller.config de %s : une clé nouvelle se relit et rejoint REVIEWED_CONFIG_KEYS avec sa raison (#712).', self::VALUES),
        );
    }

    /**
     * Le chart ne rend `allow-snippet-annotations` que s'il vaut `true` :
     * `false`, écrit ici, remplace un `true` qu'un `--reuse-values`
     * conserverait, et le contrôleur retombe sur son défaut, `false`. Une
     * annotation de snippet (`proxy_request_buffering off;` dans un
     * `configuration-snippet`) contournerait sinon les deux réglages pour son
     * Ingress. `false` se lit pareil en YAML 1.1 et 1.2 ; `no` ou `off`, non,
     * et ce test les refuse.
     */
    public function testTheHelmValuesKeepSnippetAnnotationsDisabled(): void
    {
        $values = $this->values();

        self::assertArrayHasKey('allowSnippetAnnotations', $values['controller'], \sprintf('%s doit épingler controller.allowSnippetAnnotations (#712).', self::VALUES));
        self::assertFalse($values['controller']['allowSnippetAnnotations'], \sprintf('%s : controller.allowSnippetAnnotations doit valoir false (#712).', self::VALUES));
    }

    /**
     * Lu en texte, commentaires compris (#616), hors du fichier des valeurs,
     * que les tests précédents lisent parsé (ses commentaires nomment ces
     * clés).
     */
    public function testNoOtherFileOfK8sReopensTheBodyReading(): void
    {
        $pattern = '#(?:'.implode('|', self::REFUSED_OUTSIDE_VALUES).')#i';
        $offenders = array_values(array_filter(
            KubernetesManifests::tree()->linesMatching($pattern),
            static fn (string $line): bool => !str_starts_with($line, self::VALUES.':'),
        ));

        self::assertSame([], $offenders, 'Seules les valeurs Helm du contrôleur règlent la lecture du corps à l\'ingress (#712).');
    }

    /**
     * La borne de l'ingress est celle du sidecar, au niveau `server` de
     * `k8s/base/backend-nginx.conf`. Plus basse, l'ingress refuserait en 413
     * HTML, avant le sidecar, des corps que celui-ci admet ; plus haute, la
     * sonde de `check_body_limit`, qui ne voit que le sidecar, ne dirait plus
     * ce que reçoit un client. Une borne de location (`/api/mcp`, 16k) reste
     * l'affaire du sidecar.
     */
    public function testTheIngressBoundEqualsTheSidecarServerBound(): void
    {
        $sidecar = $this->serverLevelDirective(KubernetesManifests::base()->source('backend-nginx.conf'), 'client_max_body_size');

        self::assertSame(
            $this->bytes($sidecar),
            $this->bytes(self::PINNED['proxy-body-size']),
            \sprintf('proxy-body-size (%s) doit égaler le client_max_body_size du server de backend-nginx.conf (%s) (#712).', self::PINNED['proxy-body-size'], $sidecar),
        );
    }

    /**
     * @return array<mixed>
     */
    private function values(): array
    {
        $documents = KubernetesManifests::tree()->documents(self::VALUES);
        self::assertCount(1, $documents, self::VALUES.' : un seul document attendu.');
        self::assertIsArray($documents[0]['controller'] ?? null, self::VALUES.' : controller manquant.');

        return $documents[0];
    }

    /**
     * @return array<mixed>
     */
    private function controllerConfig(): array
    {
        $config = $this->values()['controller']['config'] ?? null;
        self::assertIsArray($config, self::VALUES.' : controller.config manquant.');

        return $config;
    }

    /**
     * La valeur de la seule occurrence de `$directive` posée directement dans
     * le bloc `server`, hors de toute location. Les accolades sont comptées
     * ligne à ligne, commentaires retirés : le fichier n'en met aucune dans
     * une chaîne.
     */
    private function serverLevelDirective(string $conf, string $directive): string
    {
        $depth = 0;
        $inServer = false;
        $values = [];
        foreach (explode("\n", $conf) as $line) {
            $code = trim(explode('#', $line, 2)[0]);
            if (0 === $depth && 1 === preg_match('/\Aserver\s*\{/', $code)) {
                $inServer = true;
            }
            if ($inServer && 1 === $depth && 1 === preg_match('/\A'.preg_quote($directive, '/').'\s+(\S+);\z/', $code, $matches)) {
                $values[] = $matches[1];
            }
            $depth += substr_count($code, '{') - substr_count($code, '}');
            if (0 === $depth) {
                $inServer = false;
            }
        }
        self::assertCount(1, $values, \sprintf('backend-nginx.conf : une seule directive %s attendue au niveau server.', $directive));

        return $values[0];
    }

    /**
     * Une taille nginx (`1m`, `1024k`, `16k`, `1048576`) en octets.
     */
    private function bytes(string $size): int
    {
        self::assertSame(1, preg_match('/\A(\d+)([kKmMgG]?)\z/', $size, $matches), \sprintf('Taille nginx illisible : %s.', $size));

        return (int) $matches[1] * match (strtolower($matches[2])) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            default => 1,
        };
    }
}
