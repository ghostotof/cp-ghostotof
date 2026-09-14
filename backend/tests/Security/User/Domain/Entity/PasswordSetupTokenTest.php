<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Domain\Entity;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\UuidV7;

/**
 * PasswordSetupToken porte la seule clé étrangère entre entités du projet
 * (`password_setup_token.user_id -> cpg_user.id`) : c'est ici que se vérifie,
 * hors base de données, que les deux côtés de la relation sont bien des UUID.
 */
final class PasswordSetupTokenTest extends TestCase
{
    public function testANewTokenIsIdentifiedByAUuidV7BeforeAnyPersistence(): void
    {
        $token = $this->token();

        self::assertInstanceOf(UuidV7::class, $token->getId());
    }

    public function testTwoTokensBuiltInSequenceGetDistinctIncreasingIds(): void
    {
        $first = $this->token();
        $second = $this->token();

        self::assertNotSame($first->getId()->toRfc4122(), $second->getId()->toRfc4122());
        self::assertLessThan($second->getId()->toRfc4122(), $first->getId()->toRfc4122());
    }

    /**
     * Le côté « possédant » de la relation : l'identité du compte référencé
     * est celle de l'entité, pas un entier attribué au flush.
     */
    public function testTheReferencedUserIsIdentifiedByItsOwnUuid(): void
    {
        $user = new CpgUser('jane', 'hashed-password');
        $token = new PasswordSetupToken($user, hash('sha256', 'clear'), new \DateTimeImmutable('2026-09-05 12:00:00'));

        self::assertInstanceOf(UuidV7::class, $token->getUser()->getId());
        self::assertTrue($token->getUser()->getId()->equals($user->getId()));
    }

    private function token(): PasswordSetupToken
    {
        return new PasswordSetupToken(
            new CpgUser('jane', 'hashed-password'),
            hash('sha256', uniqid('', true)),
            new \DateTimeImmutable('2026-09-05 12:00:00'),
        );
    }
}
