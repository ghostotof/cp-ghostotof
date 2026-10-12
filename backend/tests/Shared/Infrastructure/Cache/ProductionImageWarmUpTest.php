<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Cache;

use App\Tests\Support\PhpImageBuild;
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
 *
 * Le test lit l'instruction `RUN` telle que Docker l'exécute
 * ({@see PhpImageBuild}) : un `|| true` renvoyé à la ligne suivante ne
 * lui échappe donc pas.
 */
final class ProductionImageWarmUpTest extends TestCase
{
    /**
     * Sans `set -e`, une suite de commandes séparées par `;` ne rend que le
     * statut de la dernière : un `cache:clear` en échec passerait sans bruit,
     * `|| true` ou pas.
     */
    public function testAFailingPostInstallScriptFailsTheBuild(): void
    {
        $instruction = $this->postInstallInstruction();

        self::assertMatchesRegularExpression('/^RUN set -[a-z]*e[a-z]*;/', $instruction, 'Le RUN de post-install-cmd doit commencer par set -e.');
        self::assertStringNotContainsString('set +e', $instruction);
        self::assertSame(
            0,
            preg_match('/post-install-cmd[^;]*\|\|/', $instruction),
            'Un « || » après post-install-cmd avale l\'échec du préchauffage.',
        );
    }

    /**
     * Un `cache:clear` qui réussit sans rien écrire là où l'initContainer lit
     * (un chemin de pool déplacé, des warmers sautés) donnerait la même image
     * inutilisable : le build exige au moins un fichier dans le répertoire
     * même que copie l'initContainer. Un fichier, pas une entrée :
     * PhpFilesAdapter crée son sous-répertoire de namespace dès qu'il est
     * instancié, avant toute écriture.
     */
    public function testTheBuildChecksTheSystemCacheHoldsFiles(): void
    {
        self::assertStringContainsString("\nWORKDIR ".PhpImageBuild::WORKDIR."\n", PhpImageBuild::dockerfile());
        self::assertStringStartsWith(PhpImageBuild::WORKDIR.'/', SystemCachePodVolumeTest::MOUNT_PATH);
        $directory = substr(SystemCachePodVolumeTest::MOUNT_PATH, \strlen(PhpImageBuild::WORKDIR) + 1);

        $instruction = $this->postInstallInstruction();
        $check = strpos($instruction, \sprintf('find %s -type f | grep -q .', $directory));

        self::assertNotFalse($check, \sprintf('Le build doit exiger un fichier dans %s.', $directory));
        self::assertGreaterThan(strpos($instruction, 'post-install-cmd'), $check, 'Le contrôle suit le préchauffage.');
    }

    private function postInstallInstruction(): string
    {
        return PhpImageBuild::instructionContaining('post-install-cmd');
    }
}
