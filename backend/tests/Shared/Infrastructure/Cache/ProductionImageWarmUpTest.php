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
 *
 * Le test lit l'instruction `RUN` telle que Docker l'exécute : lignes de
 * commentaire retirées, lignes de continuation jointes. Un `|| true` renvoyé à
 * la ligne suivante ne lui échappe donc pas.
 */
final class ProductionImageWarmUpTest extends TestCase
{
    private const string WORKDIR = '/var/www/backend';

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
        self::assertStringContainsString("\nWORKDIR ".self::WORKDIR."\n", $this->dockerfile());
        self::assertStringStartsWith(self::WORKDIR.'/', SystemCachePodVolumeTest::MOUNT_PATH);
        $directory = substr(SystemCachePodVolumeTest::MOUNT_PATH, \strlen(self::WORKDIR) + 1);

        $instruction = $this->postInstallInstruction();
        $check = strpos($instruction, \sprintf('find %s -type f | grep -q .', $directory));

        self::assertNotFalse($check, \sprintf('Le build doit exiger un fichier dans %s.', $directory));
        self::assertGreaterThan(strpos($instruction, 'post-install-cmd'), $check, 'Le contrôle suit le préchauffage.');
    }

    /**
     * L'instruction `RUN` qui rejoue `post-install-cmd`, sur une ligne, comme
     * Docker la passe au shell.
     */
    private function postInstallInstruction(): string
    {
        $lines = array_filter(
            explode("\n", $this->dockerfile()),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
        );
        $instructions = explode("\n", (string) preg_replace('/\\\\\n\s*/', ' ', implode("\n", $lines)));

        $matching = array_values(array_filter(
            $instructions,
            static fn (string $instruction): bool => str_contains($instruction, 'post-install-cmd'),
        ));
        self::assertCount(1, $matching, 'Une seule instruction du Dockerfile doit rejouer post-install-cmd.');

        return $matching[0];
    }

    private function dockerfile(): string
    {
        $path = \dirname(__DIR__, 5).'/docker/php/Dockerfile';
        self::assertFileIsReadable($path);

        return (string) file_get_contents($path);
    }
}
