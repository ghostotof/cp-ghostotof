<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Domain\Service;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class UsernameGeneratorTest extends TestCase
{
    public function testDerivesUsernameFromTheEmailLocalPart(): void
    {
        $generator = new UsernameGenerator($this->repositoryWithTaken());

        self::assertSame('jean.dupont', $generator->generateFromEmail('jean.dupont@example.com'));
    }

    public function testLowercasesTheLocalPart(): void
    {
        $generator = new UsernameGenerator($this->repositoryWithTaken());

        self::assertSame('caps', $generator->generateFromEmail('CAPS@example.com'));
    }

    public function testStripsCharactersOutsideTheAllowedSet(): void
    {
        $generator = new UsernameGenerator($this->repositoryWithTaken());

        // "+" retiré, "-" et "." conservés (cf. CpgUser::USERNAME_PATTERN).
        self::assertSame('marie-clairenews', $generator->generateFromEmail('marie-claire+news@example.com'));
    }

    public function testPadsALocalPartShorterThanThreeCharactersWithUser(): void
    {
        $generator = new UsernameGenerator($this->repositoryWithTaken());

        // "a+b" -> "ab" (2 car.) -> complété par "user" -> "abuser".
        self::assertSame('abuser', $generator->generateFromEmail('a+b@example.com'));
        // Partie locale sans aucun caractère autorisé -> "user".
        self::assertSame('user', $generator->generateFromEmail('***@example.com'));
    }

    public function testAppendsIncrementalSuffixWhenTheUsernameIsTaken(): void
    {
        $generator = new UsernameGenerator($this->repositoryWithTaken('jean.dupont'));

        self::assertSame('jean.dupont2', $generator->generateFromEmail('jean.dupont@example.com'));
    }

    public function testIncrementsTheSuffixUntilAFreeUsernameIsFound(): void
    {
        $generator = new UsernameGenerator($this->repositoryWithTaken('jean.dupont', 'jean.dupont2', 'jean.dupont3'));

        self::assertSame('jean.dupont4', $generator->generateFromEmail('jean.dupont@example.com'));
    }

    public function testTruncatesALongLocalPartToSixtyCharacters(): void
    {
        $generator = new UsernameGenerator($this->repositoryWithTaken());

        $result = $generator->generateFromEmail(str_repeat('a', 70).'@example.com');

        self::assertSame(str_repeat('a', 60), $result);
    }

    public function testTruncatesTheBaseToLeaveRoomForTheSuffixOnCollision(): void
    {
        $base = str_repeat('a', 60);
        $generator = new UsernameGenerator($this->repositoryWithTaken($base));

        $result = $generator->generateFromEmail($base.'@example.com');

        // Base tronquée à 59 caractères pour loger le suffixe "2" -> 60 au total.
        self::assertSame(str_repeat('a', 59).'2', $result);
    }

    public function testResultAlwaysMatchesTheUsernamePattern(): void
    {
        $generator = new UsernameGenerator($this->repositoryWithTaken());

        foreach (['a+b@x.fr', '***@x.fr', 'CAPS@x.fr', str_repeat('z', 80).'@x.fr', 'a.b-c_d@x.fr'] as $email) {
            self::assertSame(1, preg_match(CpgUser::USERNAME_PATTERN, $generator->generateFromEmail($email)), $email);
        }
    }

    /**
     * @return CpgUserRepositoryInterface&Stub
     */
    private function repositoryWithTaken(string ...$takenUsernames): CpgUserRepositoryInterface
    {
        $repository = self::createStub(CpgUserRepositoryInterface::class);
        $repository->method('findOneByUsername')->willReturnCallback(
            static fn (string $username): ?CpgUser => \in_array($username, $takenUsernames, true)
                ? new CpgUser($username, 'hashed-password')
                : null,
        );

        return $repository;
    }
}
