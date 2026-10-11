<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Le pilote enveloppé par
 * {@see \App\Shared\Infrastructure\Doctrine\DriverErrorRedactionMiddleware} : tout ce
 * qu'il fait passe au pilote d'origine, sauf la conversion des exceptions,
 * confiée à {@see RedactingExceptionConverter}.
 *
 * Une classe nommée plutôt qu'une classe anonyme dans `wrap()` : `src/` n'en
 * déclare aucune, pour que les gardes de signatures voient tout (issue #432).
 */
final class RedactingDriver extends AbstractDriverMiddleware
{
    public function getExceptionConverter(): ExceptionConverter
    {
        return new RedactingExceptionConverter(parent::getExceptionConverter());
    }
}
