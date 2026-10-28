<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Jwt;

use App\Security\Authentication\Infrastructure\Jwt\CredentialsVersionClaimListener;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\ValueObject\BaseAccessUser;
use App\Shared\Domain\Security\CredentialsVersionClaim;
use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use PHPUnit\Framework\TestCase;

/**
 * La version des identifiants entre dans le JWT **à sa création** (#650, S1,
 * F-A de la seconde relecture), lue sur le `CpgUser` dont le mot de passe vient
 * d'être vérifié — jamais relue en base. C'est ce qui ferme la course : une
 * connexion qui a lu l'ancien hachage porte l'ancienne version, quel que soit
 * l'instant où le jeton est signé.
 */
final class CredentialsVersionClaimListenerTest extends TestCase
{
    public function testTheTokenCarriesTheVersionOfTheAccountItWasCreatedFor(): void
    {
        $account = new CpgUser('jane', 'hashed-password');
        $account->replacePassword('second-hash', new DateTimeImmutable('2026-10-28 10:00:00'));
        $account->replacePassword('third-hash', new DateTimeImmutable('2026-10-28 11:00:00'));
        $event = new JWTCreatedEvent(['username' => 'jane', 'roles' => ['ROLE_USER']], $account);

        (new CredentialsVersionClaimListener())($event);

        self::assertSame(2, $event->getData()[CredentialsVersionClaim::NAME] ?? null);
        self::assertSame('jane', $event->getData()['username'], 'Les autres revendications sont intactes.');
    }

    /** Un compte jamais changé porte 0, explicitement : l'absence de revendication est celle d'un jeton d'avant le déploiement. */
    public function testAnAccountNeverChangedCarriesVersionZeroExplicitly(): void
    {
        $event = new JWTCreatedEvent(['username' => 'jane'], new CpgUser('jane', 'hashed-password'));

        (new CredentialsVersionClaimListener())($event);

        self::assertArrayHasKey(CredentialsVersionClaim::NAME, $event->getData());
        self::assertSame(0, $event->getData()[CredentialsVersionClaim::NAME]);
    }

    /** L'accès de base n'a pas de compte (ADR 0003 D6) : son jeton ne porte aucune version. */
    public function testTheBaseAccessTokenCarriesNoVersion(): void
    {
        $event = new JWTCreatedEvent(['guest' => true], new BaseAccessUser('guest-0123'));

        (new CredentialsVersionClaimListener())($event);

        self::assertSame(['guest' => true], $event->getData());
    }
}
