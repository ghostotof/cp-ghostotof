<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;

/**
 * Confie au convertisseur du pilote une copie rédigée de l'exception
 * ({@see RedactedDriverException}) : il en tire la même classe DBAL, par le
 * même SQLSTATE, mais plus rien de la valeur en cause.
 */
final readonly class RedactingExceptionConverter implements ExceptionConverter
{
    public function __construct(private ExceptionConverter $inner)
    {
    }

    public function convert(Exception $exception, ?Query $query): DriverException
    {
        return $this->inner->convert(RedactedDriverException::of($exception), $query);
    }
}
