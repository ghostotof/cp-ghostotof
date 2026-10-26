<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Kubernetes;

use App\Tests\Support\KubernetesManifests;
use PHPUnit\Framework\TestCase;

/**
 * L'ingress-nginx placé devant le sidecar lit tout le corps d'une requête
 * avant de joindre le pod, et refuse en 413 un corps de plus d'1 Mo (issue
 * #712, suivi de #702).
 *
 * L'acceptation de #702 (un multipart n'est compté par aucune zone du sidecar,
 * `.claude/rules/deploiement-nginx.md`, « Multipart bodies never reach PHP »)
 * s'appuie en partie sur ces deux réglages : un corps lent n'arrive jamais
 * lentement au sidecar, et un corps démesuré ne l'atteint pas. Ce sont les
 * valeurs par défaut du contrôleur (1.15.1, `internal/ingress/controller/config/config.go`),
 * relevées sur le cluster le 2026-10-26 : la ConfigMap du contrôleur ne les
 * pose pas, et aucun Ingress ne les surcharge par annotation.
 *
 * Une valeur par défaut n'est écrite nulle part : un changement de chart, une
 * valeur posée à la main (que `helm upgrade --reuse-values` conserve) ou une
 * annotation d'Ingress ajoutée pour un futur flux les changerait sans rien
 * faire virer au rouge. D'où l'épinglage dans les valeurs Helm, et ce test,
 * qui garde ce que le dépôt contrôle. Il ne voit pas le cluster : un réglage
 * posé à la main hors du dépôt reste l'affaire de la lecture documentée dans
 * `k8s/README.md` (prérequis n°2).
 */
final class IngressBuffersRequestBodiesTest extends TestCase
{
    private const string VALUES = 'ingress-nginx-values.yaml';

    /**
     * Les clés de la ConfigMap du contrôleur, sous `controller.config`, et
     * leur valeur attendue telle que Helm la passe (une chaîne).
     */
    private const array PINNED = [
        'proxy-request-buffering' => 'on',
        'proxy-body-size' => '1m',
    ];

    public function testTheHelmValuesPinBothSettings(): void
    {
        $documents = KubernetesManifests::tree()->documents(self::VALUES);
        self::assertCount(1, $documents, self::VALUES.' : un seul document attendu.');
        $config = $documents[0]['controller']['config'] ?? null;
        self::assertIsArray($config, self::VALUES.' : controller.config manquant.');

        foreach (self::PINNED as $key => $expected) {
            self::assertSame($expected, $config[$key] ?? null, \sprintf('%s doit épingler controller.config.%s à « %s » (#712).', self::VALUES, $key, $expected));
        }
    }

    /**
     * Ni une annotation d'Ingress (`nginx.ingress.kubernetes.io/proxy-request-buffering`),
     * ni un patch d'overlay, ni une seconde copie des valeurs ne les nomme :
     * la ConfigMap vaut pour tous les Ingress, une annotation la remplace pour
     * le sien. Lu en texte, commentaires compris (#616), hors du fichier des
     * valeurs, que le test précédent lit parsé (ses commentaires les nomment).
     */
    public function testNoOtherFileOfK8sNamesThem(): void
    {
        $pattern = '/(?:'.implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), array_keys(self::PINNED))).')/i';
        $offenders = array_values(array_filter(
            KubernetesManifests::tree()->linesMatching($pattern),
            static fn (string $line): bool => !str_starts_with($line, self::VALUES.':'),
        ));

        self::assertSame([], $offenders, 'Seules les valeurs Helm du contrôleur règlent la lecture du corps à l\'ingress (#712).');
    }
}
