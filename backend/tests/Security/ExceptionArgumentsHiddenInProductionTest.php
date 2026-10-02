<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Les traces d'exception des environnements déployés ne portent pas les
 * arguments des appels (issue #278, audit du correctif #272, constat I8).
 *
 * La valeur intégrée de PHP est `zend.exception_ignore_args = Off` : chaque
 * frame d'une trace garde alors ses arguments, un DSN avec son mot de passe, un
 * mot de passe soumis, un jeton. Aujourd'hui rien ne fuit — les signatures qui
 * les reçoivent portent `#[\SensitiveParameter]` et le formateur JSON de
 * Monolog n'écrit pas les stacktraces — mais cette protection tient signature
 * par signature : la première qui l'oublie suffit. La directive la rend globale.
 *
 * Seul `php.prod.ini` est concerné (production et préprod, l'image `preprod`
 * étant construite `FROM production`) : en dev, les arguments restent visibles,
 * c'est précisément ce qu'on veut en déboguant.
 *
 * Le test lit le fichier, comme `FpmLockWaitBoundTest` : la configuration de
 * l'image n'est pas celle du conteneur de dev qui exécute la suite.
 * `docker/php` y est monté en lecture seule pour cela.
 */
final class ExceptionArgumentsHiddenInProductionTest extends TestCase
{
    public function testDeployedImagesDropCallArgumentsFromExceptionTraces(): void
    {
        $path = \dirname(__DIR__, 3).'/docker/php/php.prod.ini';
        self::assertFileIsReadable($path);

        self::assertMatchesRegularExpression(
            '/^zend\.exception_ignore_args = On$/m',
            (string) file_get_contents($path),
        );
    }
}
