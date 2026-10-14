<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Ce que lit `docker build` pour l'image PHP : `docker/php/Dockerfile`, tous
 * stages confondus, le `.dockerignore` du contexte de build, la racine du
 * dépôt (issues #474, #489), et le `php.prod.ini` que le Dockerfile copie dans
 * l'image (issue #612).
 *
 * Le Dockerfile est lu instruction par instruction comme Docker le passe au
 * shell : lignes de commentaire retirées, lignes de continuation jointes. Un
 * `|| true` renvoyé à la ligne suivante ou une commande cachée derrière un
 * commentaire ne lui échappent donc pas.
 */
final class PhpImageBuild
{
    public const string WORKDIR = '/var/www/backend';

    public static function dockerfile(): string
    {
        return self::read('docker/php/Dockerfile');
    }

    /**
     * Les instructions du Dockerfile, chacune sur une ligne.
     *
     * @return list<string>
     */
    public static function instructions(): array
    {
        return explode("\n", (string) preg_replace('/\\\\\n\s*/', ' ', implode("\n", self::withoutComments(self::dockerfile()))));
    }

    /**
     * La seule instruction qui contient `$needle`.
     */
    public static function instructionContaining(string $needle): string
    {
        return self::single(self::instructions(), $needle, 'instruction du Dockerfile');
    }

    /**
     * La seule commande, entre deux `;` d'une instruction `RUN`, qui contient
     * `$needle`, sans les blancs qui l'entourent.
     */
    public static function commandContaining(string $needle): string
    {
        return trim(self::single(explode(';', self::instructionContaining($needle)), $needle, 'commande du Dockerfile'));
    }

    /**
     * Les motifs du `.dockerignore`, dans l'ordre, sans commentaire ni ligne
     * vide.
     *
     * @return list<string>
     */
    public static function dockerignorePatterns(): array
    {
        return array_values(array_filter(
            array_map(trim(...), self::withoutComments(self::read('.dockerignore'))),
            static fn (string $line): bool => '' !== $line,
        ));
    }

    /**
     * La valeur d'une directive de `php.prod.ini`, telle que PHP lit le
     * fichier — commentaires ignorés, guillemets retirés, la dernière
     * occurrence l'emportant — mais brute : `On` reste `On`, là où le
     * scanner normal rendrait `1`. Une directive absente fait échouer le test.
     */
    public static function productionIniDirective(string $name): string
    {
        $directives = parse_ini_string(self::read('docker/php/php.prod.ini'), false, \INI_SCANNER_RAW);
        Assert::assertIsArray($directives, 'php.prod.ini illisible.');
        Assert::assertArrayHasKey($name, $directives, \sprintf('php.prod.ini : aucune directive « %s ».', $name));
        // Une directive écrite `nom[] = …` serait lue comme une liste.
        Assert::assertIsString($directives[$name], \sprintf('php.prod.ini : « %s » n\'est pas une valeur simple.', $name));

        return $directives[$name];
    }

    /**
     * @return list<string>
     */
    private static function withoutComments(string $contents): array
    {
        return array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
        ));
    }

    /**
     * @param list<string> $candidates
     */
    private static function single(array $candidates, string $needle, string $what): string
    {
        $matching = array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => str_contains($candidate, $needle),
        ));
        Assert::assertCount(1, $matching, \sprintf('Une seule %s doit contenir « %s ».', $what, $needle));

        return $matching[0];
    }

    /**
     * Un fichier du dépôt, à partir de sa racine : le contexte de build. Le
     * conteneur de dev en monte les fichiers lus ici en lecture seule
     * (docker-compose.yml), la CI extrait le dépôt entier.
     */
    private static function read(string $path): string
    {
        $absolute = \dirname(__DIR__, 3).'/'.$path;
        Assert::assertFileIsReadable($absolute);

        return (string) file_get_contents($absolute);
    }
}
