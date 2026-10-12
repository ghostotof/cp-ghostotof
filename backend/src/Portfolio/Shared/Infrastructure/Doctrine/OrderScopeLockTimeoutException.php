<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Infrastructure\Doctrine;

use RuntimeException;
use Throwable;

/**
 * Le verrou d'un périmètre d'ordre ne s'est pas libéré avant `lock_timeout`
 * (issue #389) : 5 s pour un worker FPM (`PGOPTIONS` de www.prod.conf, #272).
 *
 * Une écriture d'ordre tient le verrou quelques millisecondes : l'attendre
 * aussi longtemps est une anomalie (transaction restée ouverte, base saturée),
 * pas un conflit à réessayer. D'où l'absence de mapping HTTP : c'est un 500
 * `critical`, mais qui porte un nom à chercher dans les journaux au lieu d'une
 * `DriverException` anonyme. Le message ne cite que la classe d'entité, jamais
 * une donnée reçue.
 */
final class OrderScopeLockTimeoutException extends RuntimeException
{
    /**
     * @param class-string $scope
     */
    public static function forScope(string $scope, Throwable $previous): self
    {
        return new self(\sprintf("Le périmètre d'ordre %s est resté verrouillé au-delà de lock_timeout.", $scope), 0, $previous);
    }
}
