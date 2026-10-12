<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Infrastructure\Log\SecurityChannelAccountRedactor;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\ValueObject\GuestUser;
use DateTimeImmutable;
use LogicException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Le canal `security` de Symfony ne cite plus l'identifiant de connexion
 * (issue #363) : ni dans le jeton de « Authenticator successful! », ni dans
 * le UserNotFoundException de « Authenticator failed. ». Le câblage (le
 * processeur sur ce canal, la vraie pile) est vérifié par
 * AccountIdentifierReachesNoLogTest ; ici, la réécriture elle-même, lue par
 * le formateur JSON de la production.
 */
final class SecurityChannelAccountRedactorTest extends TestCase
{
    private const string IDENTIFIER = 'jean.dupont';

    public function testAnAccountTokenNamesTheAccountByItsIdOnly(): void
    {
        $user = new CpgUser(self::IDENTIFIER, 'hash');
        $user->setRoles([CpgUser::ROLE_TRUSTED]);

        $line = $this->formatted(['token' => new UsernamePasswordToken($user, 'api', $user->getRoles()), 'authenticator' => 'X']);

        self::assertStringNotContainsString(self::IDENTIFIER, $line);
        self::assertStringContainsString(\sprintf('UsernamePasswordToken(userId=\"%s\", roles=\"ROLE_TRUSTED, ROLE_USER\")', $user->accountId()), $line);
        self::assertStringContainsString('"authenticator":"X"', $line, 'Les autres clés du contexte restent.');
    }

    public function testAGuestTokenKeepsItsRandomMarker(): void
    {
        $guest = new GuestUser('guest-0123abcd');

        $line = $this->formatted(['token' => new UsernamePasswordToken($guest, 'api', $guest->getRoles())]);

        self::assertStringContainsString('UsernamePasswordToken(user=\"guest-0123abcd\", roles=\"ROLE_USER\")', $line);
    }

    public function testAnyOtherUserIsDescribedByItsRolesOnly(): void
    {
        $user = new InMemoryUser(self::IDENTIFIER, null, ['ROLE_USER']);

        $tokenLine = $this->formatted(['token' => new UsernamePasswordToken($user, 'api', $user->getRoles())]);
        $userLine = $this->formatted(['user' => $user]);

        self::assertStringNotContainsString(self::IDENTIFIER, $tokenLine);
        self::assertStringContainsString('UsernamePasswordToken(roles=\"ROLE_USER\")', $tokenLine);
        self::assertStringNotContainsString(self::IDENTIFIER, $userLine);
        self::assertStringContainsString('InMemoryUser(roles=\"ROLE_USER\")', $userLine);
    }

    public function testTheMessageOfAUserNotFoundExceptionIsWithheldWhereverItSitsInTheChain(): void
    {
        $notFound = new UserNotFoundException(\sprintf('There is no user with identifier "%s".', self::IDENTIFIER));

        foreach (['nue' => $notFound, 'en previous' => new BadCredentialsException('Bad credentials.', 0, $notFound)] as $case => $exception) {
            $record = (new SecurityChannelAccountRedactor())($this->record(['exception' => $exception]));
            $line = (new JsonFormatter())->format($record);

            self::assertStringNotContainsString(self::IDENTIFIER, $line, $case);
            self::assertStringContainsString(json_encode(SecurityChannelAccountRedactor::WITHHELD, \JSON_THROW_ON_ERROR), $line, $case);
            self::assertStringContainsString(json_encode(UserNotFoundException::class, \JSON_THROW_ON_ERROR), $line, $case.' : la classe reste lisible.');
        }

        $wrapped = (new SecurityChannelAccountRedactor())($this->record(['exception' => new BadCredentialsException('Bad credentials.', 0, $notFound)]));
        self::assertIsArray($wrapped->context['exception']);
        self::assertSame(BadCredentialsException::class, $wrapped->context['exception']['class']);
        self::assertSame('Bad credentials.', $wrapped->context['exception']['message'], 'Un maillon qui ne cite rien garde son message.');
        self::assertIsArray($wrapped->context['exception']['previous']);
        self::assertSame(SecurityChannelAccountRedactor::WITHHELD, $wrapped->context['exception']['previous']['message']);
    }

    public function testARecordWithNothingToRedactIsReturnedAsIs(): void
    {
        $record = $this->record(['exception' => new LogicException('Rien à masquer.'), 'authenticator' => 'X']);

        self::assertSame($record, (new SecurityChannelAccountRedactor())($record));
    }

    /** @param array<string, mixed> $context */
    private function formatted(array $context): string
    {
        return (new JsonFormatter())->format((new SecurityChannelAccountRedactor())($this->record($context)));
    }

    /** @param array<string, mixed> $context */
    private function record(array $context): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'security', Level::Info, 'Authenticator successful!', $context);
    }
}
