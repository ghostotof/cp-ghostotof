<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Ce que lit `docker build` pour l'image PHP, depuis la racine du dépôt (le
 * contexte de build) : `docker/php/Dockerfile`, tous stages confondus, le
 * `.dockerignore` (issues #474, #489), et le `php.prod.ini` que le Dockerfile
 * copie dans l'image (issue #612).
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
     * La valeur d'une directive de `php.prod.ini`, lue par {@see self::iniDirective()}.
     */
    public static function productionIniDirective(string $name): string
    {
        return self::iniDirective(self::read('docker/php/php.prod.ini'), $name, 'php.prod.ini');
    }

    /**
     * La valeur brute d'une directive dans le texte d'un fichier ini, `$file`
     * le nommant dans les messages. Comme PHP : commentaires ignorés,
     * guillemets retirés, la dernière occurrence l'emportant. Brute, à la
     * différence du démarrage de PHP (scanner normal) : `On` reste `On` (pas
     * `1`), `1` ou `yes` ne deviennent pas `On`, un `${VAR}` n'est pas
     * développé, et une section `[PATH=…]` ou `[HOST=…]` est aplatie — le
     * fichier de l'image n'en a aucune. Une directive absente, ou écrite
     * `nom[] = …`, fait échouer le test.
     */
    public static function iniDirective(string $ini, string $name, string $file): string
    {
        $directives = parse_ini_string($ini, false, \INI_SCANNER_RAW);
        Assert::assertIsArray($directives, \sprintf('%s illisible.', $file));
        Assert::assertArrayHasKey($name, $directives, \sprintf('%s : aucune directive « %s ».', $file, $name));
        Assert::assertIsString($directives[$name], \sprintf('%s : « %s » n\'est pas une valeur simple.', $file, $name));

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
