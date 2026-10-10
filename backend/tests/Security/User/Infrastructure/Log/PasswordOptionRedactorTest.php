<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Log;

use App\Security\User\Infrastructure\Log\PasswordOptionRedactor;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Revue de #386 : `--password` n'existe plus, mais un opérateur qui la tape
 * par habitude provoque une InvalidOptionException, que le ErrorListener de
 * la console journalise en `critical` avec l'argv reconstitué (`command`) —
 * le mot de passe en clair, à chaque fois. Les chaînes ci-dessous ont la
 * forme que produit `(string) $input` (ArgvInput, ArrayInput : une valeur
 * qui n'est pas un mot est passée par `escapeshellarg()`).
 */
final class PasswordOptionRedactorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function commandsCarryingAPassword(): iterable
    {
        yield 'option et valeur jointes' => [
            "app:user:create --username=jane --password=S3cret-pass --role=ROLE_SUPER",
            "app:user:create --username=jane --password=*** --role=ROLE_SUPER",
        ];
        yield 'option et valeur séparées' => [
            "app:user:create --username=jane --password S3cret-pass",
            "app:user:create --username=jane --password ***",
        ];
        yield 'valeur entre apostrophes, apostrophe comprise' => [
            "app:user:create --password='S3cret pass'\\''s' --username=jane",
            "app:user:create --password=*** --username=jane",
        ];
        yield 'valeur donnée à --password-stdin' => [
            "app:user:create --username=jane --password-stdin=S3cret-pass",
            "app:user:create --username=jane --password-stdin=***",
        ];
    }

    #[DataProvider('commandsCarryingAPassword')]
    public function testThePasswordIsRedactedFromTheLoggedCommand(string $command, string $expected): void
    {
        $record = (new PasswordOptionRedactor())($this->record(['command' => $command, 'code' => 1]));

        self::assertSame($expected, $record->context['command']);
        self::assertSame(1, $record->context['code']);
    }

    /** `--password-stdin` sans valeur est le bon usage : l'option qui suit n'est pas un secret. */
    public function testTheStdinFlagFollowedByAnotherOptionIsLeftAlone(): void
    {
        $command = 'app:user:create --username=jane --password-stdin --role=ROLE_SUPER';

        $record = (new PasswordOptionRedactor())($this->record(['command' => $command]));

        self::assertSame($command, $record->context['command']);
    }

    public function testARecordWithoutCommandIsLeftAlone(): void
    {
        $record = $this->record(['message' => '--password=x']);

        self::assertSame($record, (new PasswordOptionRedactor())($record));
    }

    /** @param array<string, mixed> $context */
    private function record(array $context): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'console', Level::Critical, 'Error thrown while running command "{command}".', $context);
    }
}
