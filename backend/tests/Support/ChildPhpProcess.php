<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Process\Process;

/**
 * La ligne de commande d'un processus `php` lancé par un test (issue #420), et
 * l'environnement qu'il reçoit (issue #403).
 *
 * L'enfant ne reçoit pas les `<ini>` de `phpunit.dist.xml` : il part des
 * fichiers ini de la machine, qui dans le conteneur de dev laissent
 * `zend.exception_ignore_args` à Off (en CI, `php.ini-production` la pose
 * déjà, la transmission n'y change rien). Une exception non rattrapée y
 * écrirait sur la sortie d'erreur une trace avec les arguments des appels,
 * que le test recopie dans son message d'échec.
 * La commande transmet donc la valeur courante du parent, celle de PHPUnit,
 * ou celle qu'un `phpunit.xml` local a remise à 0 pour déboguer.
 */
final class ChildPhpProcess
{
    public const string DIRECTIVE = 'zend.exception_ignore_args';

    /**
     * @return non-empty-list<string> `php -d zend.exception_ignore_args=<valeur du parent>` suivi des arguments
     */
    public static function command(string ...$arguments): array
    {
        // Directive inconnue (impossible depuis PHP 7.4) : le côté sûr.
        $value = \ini_get(self::DIRECTIVE);

        return [\PHP_BINARY, '-d', self::DIRECTIVE.'='.(false === $value ? '1' : $value), ...array_values($arguments)];
    }

    /**
     * Le processus `php` fils, prêt à démarrer, dans l'environnement exact du
     * test : sa ligne de commande ({@see self::command()}) et les variables du
     * test ({@see self::testEnvironment()}). C'est ainsi que les tests de
     * concurrence lancent leurs fils (#277, #389, #435, #458).
     */
    public static function inTestEnvironment(int $timeoutSeconds, string ...$arguments): Process
    {
        return new Process(self::command(...$arguments), env: self::testEnvironment(), timeout: $timeoutSeconds);
    }

    /**
     * Les variables que phpunit.dist.xml force par `<server force="true">`
     * (APP_ENV, clés d'API factices…) ne vivent que dans `$_SERVER` : un
     * processus fils ne les hérite pas. Sans elles, Dotenv chargerait le .env
     * de dev, et une vraie ANTHROPIC_API_KEY exportée dans le shell
     * parviendrait au fils à la place de la valeur factice, ce qui
     * contournerait le filet de phpunit.dist.xml. Les transmettre
     * explicitement donne au fils l'environnement exact du test ; Process les
     * ajoute à celles qu'il hérite déjà, et elles l'emportent.
     *
     * Écrit ici seul (issue #403) : une variable à ne pas transmettre, par
     * exemple, s'exclurait ici pour tous les fils à la fois.
     *
     * @return array<string, string>
     */
    private static function testEnvironment(): array
    {
        $environment = [];
        foreach ($_SERVER as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $environment[$name] = $value;
            }
        }

        return $environment;
    }
}
