<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\PhpImageBuild;
use PHPUnit\Framework\TestCase;

/**
 * Le suivi de progression des uploads de session est éteint dans toutes les
 * images (avis GHSA-6wmf-4rv5-p3rp, CVE-2026-109619, corrigé par PHP 8.5.12).
 *
 * La valeur intégrée de PHP est `session.upload_progress.enabled = On`, et le
 * mécanisme agit **sans `session_start()`** : tout POST multipart portant un
 * champ `PHP_SESSION_UPLOAD_PROGRESS` le déclenche au démarrage de la requête,
 * avant l'application. L'avis l'a validé sur FPM, dans un pool dynamique
 * standard, comme producteur et consommateur d'un état périmé entre deux
 * requêtes d'un même worker (exécution de code à distance non authentifiée).
 * La mise à jour corrige la faille ; l'avis recommande d'éteindre quand même
 * le mécanisme lorsqu'il ne sert pas, et il ne sert pas ici : aucune session
 * (`framework.session: false`, audit A23) ni aucun upload.
 *
 * Le test lit les fichiers ({@see PhpImageBuild}), comme
 * {@see ExceptionArgumentsHiddenInProductionTest} : la configuration des
 * images n'est pas celle du conteneur de dev qui exécute la suite, ni celle de
 * `setup-php` en CI.
 *
 * Non couvert, et accepté : un `PHP_VALUE` du sidecar et les fichiers ini de
 * l'image officielle, déjà gardés par {@see ExceptionArgumentsHiddenInProductionTest}
 * pour tous les réglages ; une surcharge par `ini_set()` à l'exécution, sans
 * effet ici (le mécanisme agit avant le code de l'application).
 */
final class SessionUploadProgressDisabledTest extends TestCase
{
    private const string DIRECTIVE = 'session.upload_progress.enabled';

    private const string DEV_INI = 'docker/php/php.dev.ini';

    public function testDeployedImagesDisableSessionUploadProgress(): void
    {
        self::assertSame('Off', PhpImageBuild::productionIniDirective(self::DIRECTIVE));
    }

    /**
     * En dev aussi : le conteneur de dev sert l'API par le même FPM, et un
     * réglage qui ne vaudrait qu'en production cacherait en dev ce que le
     * mécanisme change.
     */
    public function testTheDevImageDisablesItToo(): void
    {
        self::assertSame(
            'Off',
            PhpImageBuild::iniDirective(PhpImageBuild::contextFile(self::DEV_INI), self::DIRECTIVE, basename(self::DEV_INI)),
        );
    }

    /**
     * Aucun autre fichier de configuration des images déployées ne nomme un
     * réglage `session.upload_progress.*` : chargé après `zz-app.ini`
     * (`xdebug.preprod.ini`) ou porté par le pool FPM (`php_admin_flag`), il
     * rallumerait le mécanisme ou en changerait le déclencheur.
     */
    public function testNoOtherConfigurationFileOfTheImagesNamesIt(): void
    {
        $offenders = [];
        foreach (PhpImageBuild::deployedConfigurationCopies() as $copy) {
            if (PhpImageBuild::PRODUCTION_INI === $copy->source) {
                continue;
            }
            foreach (PhpImageBuild::iniKeys(PhpImageBuild::contextFile($copy->source), $copy->source) as $key) {
                if (1 === preg_match('/session\.upload_progress/i', $key)) {
                    $offenders[] = \sprintf('%s (%s, %s) : %s', $copy->source, $copy->stage, $copy->destination, $key);
                }
            }
        }

        self::assertSame([], $offenders, 'Seul php.prod.ini règle session.upload_progress.* dans les images déployées (CVE-2026-109619).');
    }

    /**
     * Le fichier lu est bien celui que l'image de dev charge sous `conf.d/` :
     * renommé ou déplacé, le test précédent lirait un fichier mort. Le stage
     * `dev` n'est pas déployé, {@see PhpImageBuild::deployedConfigurationCopies()}
     * ne le lit pas : l'unique instruction qui nomme le fichier suffit.
     */
    public function testTheDevImageLoadsTheDevIni(): void
    {
        self::assertMatchesRegularExpression(
            '#\ACOPY\s+'.preg_quote(self::DEV_INI, '#').'\s+/usr/local/etc/php/conf\.d/[^/\s]+\.ini\z#',
            PhpImageBuild::instructionContaining(self::DEV_INI),
            'php.dev.ini n\'est plus chargé par l\'image de dev.',
        );
    }
}
