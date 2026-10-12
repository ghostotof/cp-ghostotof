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
     * Code de sortie d'un fils qui n'a pas pu se mettre en place (arguments,
     * services, barrière) : rien n'a été fait. Les fixtures des tests de
     * concurrence le rendent toutes, le test le distingue ainsi d'un échec de
     * l'opération elle-même.
     */
    public const int SETUP_FAILED = 2;

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
     * L'environnement du test, tel que `$_SERVER` le porte : les variables
     * que phpunit.dist.xml force par `<server force="true">` (APP_ENV, clés
     * d'API factices…) et celles que Dotenv a chargées. Une vraie
     * ANTHROPIC_API_KEY exportée dans le shell ne doit pas parvenir au fils à
     * la place de la valeur factice : Process ajoute ces variables à celles
     * qu'il hérite déjà, et elles l'emportent.
     *
     * Process hérite aussi, de lui-même, de `$_ENV`, où les valeurs forcées
     * figurent également sous PHPUnit (mesuré le 2026-10-12) : la copie de
     * `$_SERVER` est ce qui ne dépend pas de ce détail, et c'est elle qui fait
     * passer une valeur que le test ne pose que dans `$_SERVER`, ou qui y
     * diffère de `$_ENV`.
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
