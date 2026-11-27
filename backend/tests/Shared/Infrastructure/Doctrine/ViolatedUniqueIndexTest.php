<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Shared\Infrastructure\Doctrine\ViolatedUniqueIndex;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La lecture du nom d'index dans le message de PostgreSQL, partagée par les
 * classes qui distinguent deux unicités (issue #652, report de la T8). La
 * forme réelle du message, telle que le pilote la rend, est épinglée par
 * InviteUniqueViolationTest sur la base.
 */
final class ViolatedUniqueIndexTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function messages(): iterable
    {
        yield 'message anglais, guillemets droits' => [
            "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"uniq_mcp_client_client_id\"\nDETAIL:  Key (client_id)=(abc) already exists.",
            'uniq_mcp_client_client_id',
        ];
        yield 'message français, guillemets « »' => [
            "SQLSTATE[23505]: Unique violation: 7 ERREUR:  la valeur d'une clé dupliquée rompt la contrainte unique « uniq_cpg_user_email »\nDETAIL:  La clé « (email)=(x) » existe déjà.",
            'uniq_cpg_user_email',
        ];
        yield 'espaces dans les guillemets' => [
            'violates unique constraint "  uniq_spaced  "',
            'uniq_spaced',
        ];
        yield 'aucune citation' => [
            'SQLSTATE[23505]: Unique violation without any quoted identifier',
            null,
        ];
        yield 'citation hors [A-Za-z0-9_]' => [
            'duplicate key value violates unique constraint "a-b"',
            null,
        ];
    }

    #[DataProvider('messages')]
    public function testItReadsTheFirstQuotedIdentifier(string $driverMessage, ?string $expected): void
    {
        self::assertSame($expected, ViolatedUniqueIndex::of($this->violation($driverMessage)));
    }

    private function violation(string $driverMessage): UniqueConstraintViolationException
    {
        $driverException = new class($driverMessage, '23505') extends AbstractException {
        };

        return new UniqueConstraintViolationException($driverException, null);
    }
}
