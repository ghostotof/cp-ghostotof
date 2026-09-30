<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Lock;

use App\Shared\Infrastructure\Lock\PostgresAdvisoryLockDsnEnvVarProcessor;
use App\Shared\Infrastructure\Lock\UnsupportedLockDatabaseUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Dérive le DSN du verrou des limiteurs de `DATABASE_URL` (issue #272).
 *
 * Le schéma `+advisory` est ce qui fait choisir à `StoreFactory` un
 * `DoctrineDbalPostgreSqlStore` (advisory lock, sans table, partagé entre
 * pods) ; sans lui, `postgresql://` donne un `DoctrineDbalStore` à table.
 */
final class PostgresAdvisoryLockDsnEnvVarProcessorTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function providePostgresUrls(): iterable
    {
        yield 'postgresql' => [
            'postgresql://app:s3cret@database:5432/app?serverVersion=18&charset=utf8',
            'postgresql+advisory://app:s3cret@database:5432/app?serverVersion=18&charset=utf8',
        ];
        yield 'postgres' => ['postgres://app:s3cret@database/app', 'postgres+advisory://app:s3cret@database/app'];
        yield 'pgsql' => ['pgsql://app:s3cret@database/app', 'pgsql+advisory://app:s3cret@database/app'];
    }

    #[DataProvider('providePostgresUrls')]
    public function testItTurnsAPostgresUrlIntoAnAdvisoryLockDsn(string $databaseUrl, string $expected): void
    {
        self::assertSame($expected, $this->process($databaseUrl));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideUnsupportedUrls(): iterable
    {
        // flock/semaphore sont locaux au pod : les accepter rouvrirait #272
        // en production dès deux réplicas.
        yield 'mysql' => ['mysql://app:s3cret@database/app'];
        yield 'sqlite' => ['sqlite:///%kernel.project_dir%/var/app.db'];
        yield 'flock' => ['flock'];
        yield 'empty' => [''];
        yield 'already advisory' => ['postgresql+advisory://app:s3cret@database/app'];
    }

    #[DataProvider('provideUnsupportedUrls')]
    public function testItRefusesAnythingButAPostgresUrl(string $databaseUrl): void
    {
        $this->expectException(UnsupportedLockDatabaseUrlException::class);

        $this->process($databaseUrl);
    }

    public function testTheRefusalNeverEchoesTheUrlItCarriesAPassword(): void
    {
        try {
            $this->process('mysql://app:s3cret@database/app');
            self::fail('Une URL non PostgreSQL doit être refusée.');
        } catch (UnsupportedLockDatabaseUrlException $exception) {
            self::assertStringNotContainsString('s3cret', $exception->getMessage());
            self::assertStringNotContainsString('database', $exception->getMessage());
        }
    }

    public function testAMalformedUrlNeverLeaksItsPasswordThroughTheReportedScheme(): void
    {
        // Sans schéma, tout ce qui précède un `://` plus loin dans la chaîne
        // passerait pour le schéma — mot de passe compris.
        try {
            $this->process('app:s3cret@database/app?callback=http://example.test');
            self::fail('Une URL sans schéma doit être refusée.');
        } catch (UnsupportedLockDatabaseUrlException $exception) {
            self::assertStringNotContainsString('s3cret', $exception->getMessage());
        }
    }

    public function testItRefusesAResolvedValueThatIsNotAString(): void
    {
        $this->expectException(UnsupportedLockDatabaseUrlException::class);

        (new PostgresAdvisoryLockDsnEnvVarProcessor())->getEnv('pg_advisory', 'DATABASE_URL', static fn (): null => null);
    }

    public function testItDeclaresItsPrefixAsAString(): void
    {
        self::assertSame(['pg_advisory' => 'string'], PostgresAdvisoryLockDsnEnvVarProcessor::getProvidedTypes());
    }

    private function process(string $databaseUrl): string
    {
        return (new PostgresAdvisoryLockDsnEnvVarProcessor())->getEnv(
            'pg_advisory',
            'DATABASE_URL',
            static fn (string $name): string => 'DATABASE_URL' === $name ? $databaseUrl : throw new \LogicException($name),
        );
    }
}
