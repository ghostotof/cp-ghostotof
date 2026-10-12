<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Infrastructure\Doctrine\CpgUserUniqueViolation;
use App\Security\User\Infrastructure\Doctrine\UnrecognisedCpgUserUniqueViolationException;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * La lecture du nom d'index dans le message de PostgreSQL (issue #364). La
 * forme réelle du message, telle que le pilote la rend, est épinglée par
 * InviteUniqueViolationTest sur la base.
 */
final class CpgUserUniqueViolationTest extends TestCase
{
    /**
     * Le refus nomme l'index violé, et ne reprend jamais la valeur en conflit
     * que cite le détail de PostgreSQL (issue #356), ni dans son message ni
     * par `previous`.
     *
     * @param class-string<Throwable> $expected
     */
    #[DataProvider('violations')]
    public function testTheRefusalNamesTheViolatedIndexAndQuotesNoValue(string $driverMessage, string $expected): void
    {
        $refusal = CpgUserUniqueViolation::refusalFor($this->violation($driverMessage));

        self::assertInstanceOf($expected, $refusal);
        self::assertNull($refusal->getPrevious());
        self::assertStringNotContainsString('jean', $refusal->getMessage());
        self::assertStringNotContainsString('a.example', $refusal->getMessage());
    }

    /**
     * @return iterable<string, array{string, class-string<Throwable>}>
     */
    public static function violations(): iterable
    {
        yield 'adresse' => [self::english(CpgUser::EMAIL_UNIQUE_INDEX, 'email', 'jean@a.example'), EmailAlreadyUsedException::class];
        yield 'identifiant' => [self::english(CpgUser::USERNAME_UNIQUE_INDEX, 'username', 'jean'), UsernameAlreadyUsedException::class];
        // lc_messages en français : chevrons, et espaces insécables autour.
        yield 'identifiant, message français' => [
            "ERREUR:  la valeur d'une clé dupliquée rompt la contrainte unique «\u{a0}".CpgUser::USERNAME_UNIQUE_INDEX."\u{a0}»\nDÉTAIL : La clé « (username)=(jean) » existe déjà.",
            UsernameAlreadyUsedException::class,
        ];
        // Le DETAIL cite la valeur : un identifiant qui porte le nom de l'index
        // de l'adresse ne doit pas faire prendre la violation pour une
        // adresse prise.
        yield 'identifiant qui cite l\'index de l\'adresse' => [
            self::english(CpgUser::USERNAME_UNIQUE_INDEX, 'username', CpgUser::EMAIL_UNIQUE_INDEX),
            UsernameAlreadyUsedException::class,
        ];
        yield 'adresse qui cite l\'index de l\'identifiant' => [
            self::english(CpgUser::EMAIL_UNIQUE_INDEX, 'email', '"'.CpgUser::USERNAME_UNIQUE_INDEX.'"@a.example'),
            EmailAlreadyUsedException::class,
        ];
        yield 'index inconnu' => [self::english('uniq_cpg_user_other', 'other', 'x'), UnrecognisedCpgUserUniqueViolationException::class];
        yield 'aucun index cité' => ['ERROR:  duplicate key value', UnrecognisedCpgUserUniqueViolationException::class];
    }

    public function testAnUnrecognisedIndexIsNamedForTheOperator(): void
    {
        $refusal = CpgUserUniqueViolation::refusalFor($this->violation(self::english('uniq_cpg_user_other', 'other', 'jean')));

        self::assertStringContainsString('uniq_cpg_user_other', $refusal->getMessage());
    }

    private static function english(string $index, string $column, string $value): string
    {
        return \sprintf("SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"%s\"\nDETAIL:  Key (%s)=(%s) already exists.", $index, $column, $value);
    }

    private function violation(string $driverMessage): UniqueConstraintViolationException
    {
        $driverException = new class($driverMessage, '23505') extends AbstractException {
        };

        return new UniqueConstraintViolationException($driverException, null);
    }
}
