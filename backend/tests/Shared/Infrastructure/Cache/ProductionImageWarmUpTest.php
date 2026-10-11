<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;

/**
 * Un préchauffage qui échoue fait échouer la construction de l'image (issue
 * #474).
 *
 * Le stage `production` du Dockerfile rejoue `post-install-cmd`, soit
 * `cache:clear` en `prod`, qui produit `var/cache/prod/pools/system`.
 * L'initContainer `seed-cache-system` copie ce répertoire dans l'`emptyDir`
 * des pods (issue #288, {@see SystemCachePodVolumeTest}) : sans lui, la copie
 * échoue et aucun pod ne démarre. Le script était suivi de `|| true`, qui
 * avalait tout échec ; la première version de #468 a ainsi produit une image
 * sans cache système, construite sans erreur. On ne l'aurait vu qu'au
 * déploiement en préprod.
 */
final class ProductionImageWarmUpTest extends TestCase
{
    public function testAFailingPostInstallScriptFailsTheBuild(): void
    {
        $dockerfile = $this->dockerfile();

        self::assertStringContainsString('composer run-script --no-dev post-install-cmd', $dockerfile);
        // Pas d'assertion sur le texte entier : son échec recopierait tout le
        // Dockerfile au lieu de nommer la faute.
        self::assertSame(
            0,
            preg_match('/post-install-cmd[^\n;]*\|\|/', $dockerfile),
            'Un « || » après post-install-cmd avale l\'échec du préchauffage.',
        );
    }

    /**
     * Un `cache:clear` qui réussit sans rien écrire là où l'initContainer lit
     * (un chemin de pool déplacé, un préchauffage sauté) donnerait la même
     * image inutilisable : le build vérifie le répertoire lui-même.
     */
    public function testTheBuildChecksTheSystemCacheWasWarmed(): void
    {
        self::assertTrue(
            str_contains($this->dockerfile(), 'test -n "$(ls -A var/cache/prod/pools/system)"'),
            'Le stage production doit vérifier que var/cache/prod/pools/system est rempli.',
        );
    }

    private function dockerfile(): string
    {
        $path = \dirname(__DIR__, 5).'/docker/php/Dockerfile';
        self::assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }
}
