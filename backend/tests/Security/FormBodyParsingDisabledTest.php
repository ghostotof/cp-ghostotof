<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\PhpImageBuild;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHP n'analyse aucun corps de formulaire dans les images (issue #686, suivi
 * de l'avis GHSA-w8hw-w9fx-j79w, CVE-2026-109618, corrigé par PHP 8.5.12).
 *
 * L'avis déclenche son OOM surtout par l'analyse **multipart** que PHP fait
 * avant l'application. L'API ne parle que JSON, aucune route ne lit de
 * formulaire, et le sidecar nginx refuse déjà ces corps en 415
 * (`tools/check-backend-nginx-rate-limits.sh`) : ces réglages sont la seconde
 * barrière, pour une requête qui atteindrait FPM sans lui. Mesuré sous PHP
 * 8.5 (#686), chacun ferme un chemin que les autres laissent ouvert :
 *
 * - `enable_post_data_reading = Off` coupe l'analyse au démarrage d'un POST,
 *   mais **pas** `request_parse_body()`, que Symfony appelle pour PUT,
 *   DELETE, PATCH et QUERY dans `Request::createFromGlobals()` ;
 * - `max_multipart_body_parts = 0` fait refuser par ce second chemin toute
 *   partie multipart (`RequestParseBodyException`, que Symfony rattrape) —
 *   `-1`, la valeur intégrée, en admettait `max_input_vars + max_file_uploads` ;
 * - `file_uploads = Off` ne fait sauter que les parties fichier, les champs
 *   restant analysés : plus aucun fichier temporaire écrit ;
 * - `post_max_size = 1M`, la borne du corps entier du nginx (16M ou 32M
 *   avant), pour les deux chemins, urlencoded compris.
 *
 * Reste analysé, et accepté : un corps urlencoded en PUT, DELETE ou PATCH,
 * par `request_parse_body()` — hors du parseur multipart de l'avis, borné par
 * `post_max_size` et `max_input_vars`, et refusé en amont par nginx. Mesuré
 * avec ces deux fichiers sous `php -n -c`.
 *
 * `max_input_vars` et `max_input_nesting_level` gardent leur valeur intégrée,
 * par décision : ils bornent aussi `$_GET` et `$_COOKIE`.
 *
 * Le test lit les fichiers ({@see PhpImageBuild}), comme
 * {@see SessionUploadProgressDisabledTest}, avec les mêmes limites acceptées.
 */
final class FormBodyParsingDisabledTest extends TestCase
{
    private const string DEV_INI = 'docker/php/php.dev.ini';

    /**
     * Valeurs brutes attendues, telles qu'écrites dans le fichier.
     */
    private const array DIRECTIVES = [
        'enable_post_data_reading' => 'Off',
        'max_multipart_body_parts' => '0',
        'file_uploads' => 'Off',
        'post_max_size' => '1M',
    ];

    /**
     * Sans objet une fois `file_uploads` éteint ; gardé, il laisserait croire
     * qu'un upload est attendu.
     */
    private const string OBSOLETE_DIRECTIVE = 'upload_max_filesize';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function directives(): iterable
    {
        foreach (self::DIRECTIVES as $name => $value) {
            yield $name => [$name, $value];
        }
    }

    #[DataProvider('directives')]
    public function testDeployedImagesSetIt(string $name, string $expected): void
    {
        self::assertSame($expected, PhpImageBuild::productionIniDirective($name));
    }

    /**
     * En dev aussi : le conteneur de dev sert l'API par le même FPM, et un
     * réglage qui ne vaudrait qu'en production cacherait en dev ce qu'il
     * change.
     */
    #[DataProvider('directives')]
    public function testTheDevImageSetsItToo(string $name, string $expected): void
    {
        self::assertSame(
            $expected,
            PhpImageBuild::iniDirective(PhpImageBuild::contextFile(self::DEV_INI), $name, basename(self::DEV_INI)),
        );
    }

    public function testNeitherIniKeepsUploadMaxFilesize(): void
    {
        foreach ([PhpImageBuild::PRODUCTION_INI, self::DEV_INI] as $file) {
            self::assertNotContains(
                self::OBSOLETE_DIRECTIVE,
                PhpImageBuild::iniKeys(PhpImageBuild::contextFile($file), $file),
                \sprintf('%s règle encore %s, sans objet sans upload (#686).', $file, self::OBSOLETE_DIRECTIVE),
            );
        }
    }

    /**
     * Aucun autre fichier de configuration des images déployées ne nomme ces
     * réglages : chargé après `zz-app.ini` (`xdebug.preprod.ini`) ou porté
     * par le pool FPM (`php_admin_value`), il rouvrirait l'analyse.
     */
    public function testNoOtherConfigurationFileOfTheImagesNamesThem(): void
    {
        $watched = [...array_keys(self::DIRECTIVES), self::OBSOLETE_DIRECTIVE];
        $offenders = [];
        foreach (PhpImageBuild::deployedConfigurationCopies() as $copy) {
            if (PhpImageBuild::PRODUCTION_INI === $copy->source) {
                continue;
            }
            foreach (PhpImageBuild::iniKeys(PhpImageBuild::contextFile($copy->source), $copy->source) as $key) {
                foreach ($watched as $directive) {
                    if (str_contains(strtolower($key), $directive)) {
                        $offenders[] = \sprintf('%s (%s, %s) : %s', $copy->source, $copy->stage, $copy->destination, $key);
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'Seul php.prod.ini règle l\'analyse des corps de formulaire dans les images déployées (#686).');
    }
}
