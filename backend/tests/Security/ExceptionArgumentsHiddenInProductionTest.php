<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\PhpImageBuild;
use PHPUnit\Framework\TestCase;

/**
 * Les traces d'exception des environnements déployés ne portent pas les
 * arguments des appels (issue #278, audit du correctif #272, constat I8).
 *
 * La valeur intégrée de PHP est `zend.exception_ignore_args = Off` : chaque
 * frame d'une trace garde alors ses arguments, un DSN avec son mot de passe, un
 * mot de passe soumis, un jeton. Aujourd'hui rien ne fuit — les signatures qui
 * les reçoivent portent `#[SensitiveParameter]` et le formateur JSON de
 * Monolog n'écrit pas les stacktraces — mais cette protection tient signature
 * par signature : la première qui l'oublie suffit. La directive la rend globale.
 *
 * Seul `php.prod.ini` la pose (production et préprod, l'image `preprod`
 * étant construite `FROM production`) : en dev, les arguments restent visibles,
 * c'est précisément ce qu'on veut en déboguant.
 *
 * Le test lit les fichiers ({@see PhpImageBuild}) : la configuration de
 * l'image n'est pas celle du conteneur de dev qui exécute la suite.
 * `docker/php` y est monté en lecture seule pour cela.
 *
 * Poser la directive ne suffit pas : toute autre source de réglages que
 * l'image applique peut la contredire (issue #616). Le garde porte donc sur
 * chaque fichier que le Dockerfile copie dans la configuration de PHP ou de
 * FPM des stages déployés — liste lue dans le Dockerfile, jamais écrite ici :
 * un fichier ajouté demain est vu sans toucher au test. `xdebug.preprod.ini`,
 * chargé après `zz-app.ini` dans l'image `preprod`, l'emporterait sur lui ;
 * un `php_admin_flag[…]` du pool FPM l'emporterait sur tout fichier ini.
 *
 * Non couvert, et accepté : les fichiers que l'image officielle range
 * elle-même dans la configuration (`docker-php-ext-*.ini`,
 * `php-fpm.d/docker.conf`, `www.conf`), absents du dépôt — seule une image
 * construite les montrerait, au prix d'un `docker build` dans la suite.
 */
final class ExceptionArgumentsHiddenInProductionTest extends TestCase
{
    private const string PRODUCTION_INI = 'docker/php/php.prod.ini';

    /**
     * Le nom de la directive, sans son préfixe et sans casse : un garde qui
     * refuse doit refuser large.
     */
    private const string DIRECTIVE_NAME = '/exception_ignore_args/i';

    public function testDeployedImagesDropCallArgumentsFromExceptionTraces(): void
    {
        self::assertSame('On', PhpImageBuild::productionIniDirective('zend.exception_ignore_args'));
        self::assertContains(
            self::PRODUCTION_INI,
            PhpImageBuild::deployedConfigurationCopies(),
            'php.prod.ini n\'est plus copié dans la configuration des images déployées : la directive qu\'il pose ne s\'y applique pas.',
        );
    }

    /**
     * Aucun autre fichier de configuration des images ne nomme la directive :
     * ni un ini, ni le pool FPM (`php_value`, `php_flag`, `php_admin_value`,
     * `php_admin_flag`), qu'ils la confirment ou la contredisent — une seule
     * source, `php.prod.ini`.
     */
    public function testNoOtherConfigurationFileOfTheImagesNamesTheDirective(): void
    {
        $offenders = [];
        foreach (PhpImageBuild::deployedConfigurationCopies() as $destination => $source) {
            if (self::PRODUCTION_INI === $source) {
                continue;
            }
            foreach (PhpImageBuild::iniKeys(PhpImageBuild::contextFile($source), $source) as $key) {
                if (1 === preg_match(self::DIRECTIVE_NAME, $key)) {
                    $offenders[] = \sprintf('%s (%s) : %s', $source, $destination, $key);
                }
            }
        }

        self::assertSame([], $offenders, 'Seul php.prod.ini règle zend.exception_ignore_args dans les images déployées (issue #616).');
    }
}
