<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\DriverErrorRedactionMiddleware;
use App\Shared\Infrastructure\Doctrine\RedactedDriverException;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Exception;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Le pilote que pose {@see DriverErrorRedactionMiddleware} confie au
 * convertisseur d'origine une exception rédigée, jamais celle du serveur
 * (issue #428, extrait d'une classe anonyme par #432). Sans base : le
 * parcours sur le vrai serveur est celui de
 * {@see DriverErrorRedactionMiddlewareTest}.
 */
final class RedactingDriverTest extends TestCase
{
    public function testTheOriginalConverterOnlyEverReceivesARedactedException(): void
    {
        $converter = new class implements ExceptionConverter {
            public ?Exception $received = null;

            public function convert(Exception $exception, ?Query $query): DriverException
            {
                $this->received = $exception;

                return new DriverException($exception, $query);
            }
        };
        $driver = self::createStub(Driver::class);
        $driver->method('getExceptionConverter')->willReturn($converter);

        $pdoException = new PDOException("ERROR: x\nDETAIL: Key (email)=(sentinel@example.com) already exists.");
        $pdoException->errorInfo = ['23505', 7, 'x'];
        (new DriverErrorRedactionMiddleware())->wrap($driver)->getExceptionConverter()->convert(PdoDriverException::new($pdoException), null);

        self::assertInstanceOf(RedactedDriverException::class, $converter->received);
        self::assertSame('23505', $converter->received->getSQLState());
        self::assertStringNotContainsString('sentinel', $converter->received->getMessage());
    }
}
