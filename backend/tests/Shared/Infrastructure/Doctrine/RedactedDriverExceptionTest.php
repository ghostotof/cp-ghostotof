<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\RedactedDriverException;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les formes de message de PostgreSQL qui citent une valeur, au-delà de
 * celles que {@see DriverErrorRedactionMiddlewareTest} produit sur la vraie
 * base (issue #428).
 */
final class RedactedDriverExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'clé en conflit' => [
            "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"uniq_email\"\nDETAIL:  Key (email)=(sentinel@example.com) already exists.",
            'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "uniq_email"',
        ];
        yield 'paramètre lié refusé' => [
            "SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: \"sentinel\"\nCONTEXT:  unnamed portal parameter \$1 = '...'",
            'SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input syntax for type uuid: "(valeur masquée)"',
        ];
        yield 'valeur d\'enum' => [
            'ERROR:  invalid input value for enum mood: "sentinel"',
            'ERROR:  invalid input value for enum mood: "(valeur masquée)"',
        ];
        yield 'valeur hors bornes' => [
            'ERROR:  value "99999999999sentinel" is out of range for type integer',
            'ERROR:  value "(valeur masquée)" is out of range for type integer',
        ];
        // Les identifiants cités sans deux-points restent : ils disent où chercher.
        yield 'colonne et table' => [
            'ERROR:  null value in column "password" of relation "cpg_user" violates not-null constraint',
            'ERROR:  null value in column "password" of relation "cpg_user" violates not-null constraint',
        ];
    }

    #[DataProvider('messages')]
    public function testTheMessageKeepsNoValue(string $message, string $redacted): void
    {
        self::assertSame($redacted, RedactedDriverException::redact($message));
    }

    public function testTheSqlStateAndCodeAreKeptAndTheChainIsCut(): void
    {
        $pdoException = new PDOException("ERROR: x\nDETAIL: Key (email)=(sentinel@example.com) already exists.");
        $pdoException->errorInfo = ['23505', 7, 'x'];
        $original = PdoDriverException::new($pdoException);

        $redacted = RedactedDriverException::of($original);

        self::assertSame('23505', $redacted->getSQLState());
        self::assertSame($original->getCode(), $redacted->getCode());
        self::assertNull($redacted->getPrevious());
        self::assertStringNotContainsString('sentinel', $redacted->getMessage());
    }
}
