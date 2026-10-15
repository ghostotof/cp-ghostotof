<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Ce que lit `docker build` pour l'image PHP, depuis la racine du dépôt (le
 * contexte de build) : `docker/php/Dockerfile`, tous stages confondus, le
 * `.dockerignore` (issues #474, #489), et le `php.prod.ini` (issue #612) et le
 * `www.prod.conf` (issue #615) que le Dockerfile copie dans l'image — et,
 * plus généralement, chaque fichier que les stages déployés copient dans la
 * configuration de PHP ou de FPM (issue #616).
 *
 * Le Dockerfile est lu instruction par instruction comme Docker le passe au
 * shell : lignes de commentaire retirées, lignes de continuation jointes. Un
 * `|| true` renvoyé à la ligne suivante ou une commande cachée derrière un
 * commentaire ne lui échappent donc pas.
 */
final class PhpImageBuild
{
    public const string WORKDIR = '/var/www/backend';

    /**
     * Les stages que le Makefile et la pipeline construisent et déploient
     * (`--target production`, `--target preprod`).
     */
    public const array DEPLOYED_STAGES = ['production', 'preprod'];

    /**
     * Où l'image officielle range la configuration de PHP (`php.ini`,
     * `conf.d/`) et de FPM (`php-fpm.conf`, `php-fpm.d/`).
     */
    private const string CONFIGURATION_ROOT = '/usr/local/etc/';

    /**
     * Un `COPY` (ou `ADD`) d'un seul fichier du contexte de build : options
     * de propriété et de mode seulement, source puis destination.
     */
    private const string SINGLE_FILE_COPY = '/\A(?:COPY|ADD)\s+(?:--(?:chown|chmod)=\S+\s+)*([^\s\[\]"]+)\s+(\S+)\z/i';

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
        return self::instructionsOf(self::dockerfile());
    }

    /**
     * Les instructions d'un texte de Dockerfile, chacune sur une ligne.
     *
     * @return list<string>
     */
    public static function instructionsOf(string $dockerfile): array
    {
        return explode("\n", (string) preg_replace('/\\\\\n\s*/', ' ', implode("\n", self::withoutComments($dockerfile))));
    }

    /**
     * Les instructions des stages déployés et de leurs ancêtres, dans l'ordre
     * du Dockerfile (issue #616).
     *
     * @return list<string>
     */
    public static function deployedInstructions(): array
    {
        return self::deployedInstructionsOf(self::dockerfile());
    }

    /**
     * Les instructions des {@see self::DEPLOYED_STAGES} et des stages dont ils
     * descendent par `FROM <stage>`, `FROM` compris, dans l'ordre du texte.
     * Un stage voisin (`dev`) en est absent, comme les `ARG` globaux d'avant
     * le premier `FROM`. Docker lit les noms de stage sans casse, ce lecteur
     * aussi. Un stage déployé absent fait échouer le test.
     *
     * @return list<string>
     */
    public static function deployedInstructionsOf(string $dockerfile): array
    {
        // Stage => stage dont il descend (null pour une image extérieure),
        // et stage => ses instructions.
        /** @var array<string, ?string> $parents */
        $parents = [];
        /** @var array<string, list<string>> $instructionsByStage */
        $instructionsByStage = [];
        $current = null;
        foreach (self::instructionsOf($dockerfile) as $instruction) {
            if (1 === preg_match('/\AFROM\s+(?:--\S+\s+)*(\S+)(?:\s+AS\s+(\S+))?\s*\z/i', trim($instruction), $from)) {
                $base = strtolower($from[1]);
                $current = strtolower($from[2] ?? (string) \count($parents));
                $parents[$current] = \array_key_exists($base, $parents) ? $base : null;
                $instructionsByStage[$current] = [];
            }
            if (null !== $current && '' !== trim($instruction)) {
                $instructionsByStage[$current][] = $instruction;
            }
        }

        $deployed = [];
        foreach (self::DEPLOYED_STAGES as $stage) {
            Assert::assertArrayHasKey($stage, $parents, \sprintf('Dockerfile : aucun stage « %s ».', $stage));
            for ($name = $stage; null !== $name; $name = $parents[$name]) {
                $deployed[$name] = true;
            }
        }

        $instructions = [];
        foreach ($instructionsByStage as $name => $stageInstructions) {
            if (isset($deployed[$name])) {
                array_push($instructions, ...$stageInstructions);
            }
        }

        return $instructions;
    }

    /**
     * Les fichiers que les images déployées reçoivent dans la configuration
     * de PHP ou de FPM, destination => source (issue #616), lus par
     * {@see self::configurationCopiesOf()}.
     *
     * @return array<string, string>
     */
    public static function deployedConfigurationCopies(): array
    {
        return self::configurationCopiesOf(self::dockerfile());
    }

    /**
     * Les `COPY` et `ADD` des stages déployés vers `/usr/local/etc/`,
     * destination => source ; une destination en répertoire reçoit le nom du
     * fichier source, comme Docker la complète. Toute autre instruction de ces
     * stages qui nomme ce répertoire — une copie depuis un stage (`--from`),
     * plusieurs sources, la forme JSON, un `RUN` qui y écrit — fait échouer le
     * test : ce que le garde ne sait pas lire, il le refuse.
     *
     * @return array<string, string>
     */
    public static function configurationCopiesOf(string $dockerfile): array
    {
        $copies = [];
        foreach (self::deployedInstructionsOf($dockerfile) as $instruction) {
            if (!str_contains($instruction, self::CONFIGURATION_ROOT)) {
                continue;
            }
            Assert::assertSame(
                1,
                preg_match(self::SINGLE_FILE_COPY, trim($instruction), $copy),
                \sprintf('« %s » écrit dans la configuration de PHP ou de FPM sans être la copie d\'un seul fichier du contexte de build : le garde ne saurait pas lire ce que l\'image y reçoit.', $instruction),
            );
            $destination = str_ends_with($copy[2], '/') ? $copy[2].basename($copy[1]) : $copy[2];
            $copies[$destination] = $copy[1];
        }

        return $copies;
    }

    /**
     * Un fichier du contexte de build, à partir de sa racine.
     */
    public static function contextFile(string $path): string
    {
        return self::read($path);
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
     * Le texte de `www.prod.conf`, le pool FPM que le Dockerfile copie juste
     * après `php.prod.ini` (issue #615).
     */
    public static function productionPool(): string
    {
        return self::read('docker/php/www.prod.conf');
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
     * Toutes les clés d'un texte ini, à toute profondeur et dans l'ordre :
     * sections, directives, clés de tableau (`php_admin_flag[nom]`, la forme
     * du pool FPM, que FPM lit avec le même analyseur que PHP). `$file` le
     * nomme dans les messages.
     *
     * @return list<string>
     */
    public static function iniKeys(string $ini, string $file): array
    {
        $parsed = parse_ini_string($ini, true, \INI_SCANNER_RAW);
        Assert::assertIsArray($parsed, \sprintf('%s illisible.', $file));

        return self::keysOf($parsed);
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function keysOf(array $values): array
    {
        $keys = [];
        foreach ($values as $key => $value) {
            $keys[] = (string) $key;
            if (\is_array($value)) {
                array_push($keys, ...self::keysOf($value));
            }
        }

        return $keys;
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
        Assert::assertTrue(is_file($absolute), \sprintf('%s n\'est pas un fichier.', $path));

        return (string) file_get_contents($absolute);
    }
}
