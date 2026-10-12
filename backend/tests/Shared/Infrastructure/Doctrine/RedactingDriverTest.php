<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\DriverErrorRedactionMiddleware;
use App\Shared\Infrastructure\Doctrine\RedactedDriverException;
use App\Shared\Infrastructure\Doctrine\RedactingDriver;
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
 * {@see \App\Tests\Shared\Infrastructure\Doctrine\DriverErrorRedactionMiddlewareTest}.
 */
final class RedactingDriverTest extends TestCase
{
    /** Ce que le convertisseur du pilote d'origine a reçu. */
    private ?Exception $received = null;

    private ?Query $receivedQuery = null;

    private RedactingDriver $driver;

    protected function setUp(): void
    {
        $original = self::createStub(ExceptionConverter::class);
        $original->method('convert')->willReturnCallback(function (Exception $exception, ?Query $query): DriverException {
            $this->received = $exception;
            $this->receivedQuery = $query;

            return new DriverException($exception, $query);
        });
        $wrapped = self::createStub(Driver::class);
        $wrapped->method('getExceptionConverter')->willReturn($original);
        $this->driver = new RedactingDriver($wrapped);
    }

    public function testTheMiddlewareWrapsTheDriverInARedactingOne(): void
    {
        self::assertInstanceOf(RedactingDriver::class, (new DriverErrorRedactionMiddleware())->wrap(self::createStub(Driver::class)));
    }

    public function testTheOriginalConverterReceivesTheRedactedExceptionWithItsSqlState(): void
    {
        $converter = $this->driver->getExceptionConverter();
        $converter->convert($this->serverException('23505'), null);

        self::assertInstanceOf(RedactedDriverException::class, $this->received);
        self::assertSame('23505', $this->received->getSQLState());
        self::assertStringNotContainsString('sentinel', $this->received->getMessage());
    }

    /** Sans SQLSTATE (une erreur qui ne vient pas du serveur), rien n'est inventé. */
    public function testAnExceptionWithoutSqlStateStaysWithout(): void
    {
        $converter = $this->driver->getExceptionConverter();
        $converter->convert($this->serverException(null), null);

        self::assertInstanceOf(RedactedDriverException::class, $this->received);
        self::assertNull($this->received->getSQLState());
    }

    public function testTheQueryReachesTheOriginalConverterUntouched(): void
    {
        $query = new Query('SELECT 1', [], []);

        $converter = $this->driver->getExceptionConverter();
        $converter->convert($this->serverException('22P02'), $query);

        self::assertSame($query, $this->receivedQuery);
    }

    private function serverException(?string $sqlState): PdoDriverException
    {
        $pdoException = new PDOException("ERROR: x\nDETAIL: Key (email)=(sentinel@example.com) already exists.");
        $pdoException->errorInfo = null === $sqlState ? null : [$sqlState, 7, 'x'];

        return PdoDriverException::new($pdoException);
    }
}
