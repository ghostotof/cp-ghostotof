<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Infrastructure\Doctrine;

use RuntimeException;
use Throwable;

/**
 * La ligne d'une technologie est restée verrouillée au-delà de `lock_timeout`
 * (issue #545). Ses détenteurs la gardent quelques millisecondes : au-delà,
 * c'est une anomalie, pas un conflit à réessayer. D'où l'absence de mapping
 * HTTP : un 500 `critical` qui porte un nom, comme pour les autres verrous du
 * squelette {@see \App\Shared\Infrastructure\Lock\PostgresLockedTransaction}.
 * Le message ne cite que l'UUID.
 */
final class ExperienceTechnologyLockTimeoutException extends RuntimeException
{
    public static function forTechnology(string $technologyId, Throwable $previous): self
    {
        return new self(\sprintf('La technologie %s est restée verrouillée au-delà de lock_timeout.', $technologyId), 0, $previous);
    }
}
