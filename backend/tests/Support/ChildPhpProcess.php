<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * La ligne de commande d'un processus `php` lancé par un test (issue #420).
 *
 * L'enfant ne reçoit pas les `<ini>` de `phpunit.dist.xml` : il part de la
 * configuration du conteneur, où `zend.exception_ignore_args` vaut Off. Une
 * exception non rattrapée y écrirait sur la sortie d'erreur une trace avec
 * les arguments des appels, que le test recopie dans son message d'échec.
 * La commande transmet donc la valeur courante du parent, celle de PHPUnit,
 * ou celle qu'un `phpunit.xml` local a remise à 0 pour déboguer.
 */
final class ChildPhpProcess
{
    private const string DIRECTIVE = 'zend.exception_ignore_args';

    /**
     * @return non-empty-list<string> `php -d zend.exception_ignore_args=<valeur du parent>` suivi des arguments
     */
    public static function command(string ...$arguments): array
    {
        // Directive inconnue (impossible depuis PHP 7.4) : le côté sûr.
        $value = \ini_get(self::DIRECTIVE);

        return [\PHP_BINARY, '-d', self::DIRECTIVE.'='.(false === $value ? '1' : $value), ...array_values($arguments)];
    }
}
