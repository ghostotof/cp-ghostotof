<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Application\DeadlockRetryInterface;
use App\Security\User\Application\PasswordSetupService;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Exception\UnusablePasswordSetupLinkException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Domain\ValueObject\TokenClaim;
use App\Security\User\Infrastructure\Doctrine\DoctrineDeadlockRetry;
use App\Shared\Application\Security\AccountAccessWithdrawal;
use App\Shared\Application\Security\AccountMcpConnectionsRevokerInterface;
use App\Shared\Application\Security\RevokedAccountMcpConnections;
use App\Tests\Support\DeadlockedStatement;
use App\Tests\Support\TestCredentials;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordSetupServiceTest extends TestCase
{
    private const string CLEAR_TOKEN = 'a-clear-token-value';

    public function testValidateLooksUpTheTokenByItsSha256Hash(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::once())
            ->method('findOneByTokenHash')
            ->with(hash('sha256', self::CLEAR_TOKEN))
            ->willReturn($this->usableToken($clock));

        // Valider n'active rien : le journal ne doit rien dire.
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('accountActivated');

        $this->service($tokenRepository, self::createStub(CpgUserRepositoryInterface::class), self::createStub(UserPasswordHasherInterface::class), $clock, $auditLogger)
            ->validate(self::CLEAR_TOKEN);
    }

    public function testValidateRejectsAnUnknownToken(): void
    {
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn(null);

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository)->validate(self::CLEAR_TOKEN);
    }

    public function testValidateRejectsAnExpiredToken(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $expired = new PasswordSetupToken(
            new CpgUser('jane', ''),
            hash('sha256', self::CLEAR_TOKEN),
            new DateTimeImmutable('2026-09-09 10:00:00'),
        );
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($expired);

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, clock: $clock)->validate(self::CLEAR_TOKEN);
    }

    public function testValidateRejectsAnAlreadyUsedToken(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $used = $this->usableToken($clock);
        $used->markUsed(new DateTimeImmutable('2026-09-10 09:00:00'));
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($used);

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, clock: $clock)->validate(self::CLEAR_TOKEN);
    }

    /**
     * Issue #356 : un jeton inconnu est tracé sur `security_audit`, sans le
     * jeton — sa seule autre trace était la ligne générique du noyau, sans IP
     * ni chemin.
     */
    public function testAnUnknownTokenIsLoggedAsRejected(): void
    {
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn(null);

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('passwordSetupTokenRejected');
        $auditLogger->expects(self::never())->method('passwordSetupTokenReplayed');

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, auditLogger: $auditLogger)->validate(self::CLEAR_TOKEN);
    }

    /**
     * Issue #356 : un lien déjà consommé est tracé comme rejoué, avec le
     * compte visé, côté journal seulement — la réponse est celle de tout lien
     * inutilisable (issue #362), qui ne dit pas à l'appelant que le lien a servi.
     */
    public function testAnAlreadyUsedTokenIsLoggedAsReplayedForItsAccount(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', 'hashed-password');
        $used = $this->tokenFor($user, $clock);
        $used->markUsed(new DateTimeImmutable('2026-09-10 09:00:00'));
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($used);

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('passwordSetupTokenReplayed')->with($user);
        $auditLogger->expects(self::never())->method('passwordSetupTokenRejected');

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, clock: $clock, auditLogger: $auditLogger)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
    }

    /**
     * Un lien simplement expiré est le cas ordinaire d'une invitation ouverte
     * trop tard : aucun événement de sécurité, le refus en `info` suffit.
     */
    public function testAnExpiredButUnusedTokenIsNotASecurityEvent(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $expired = new PasswordSetupToken(
            new CpgUser('jane', ''),
            hash('sha256', self::CLEAR_TOKEN),
            new DateTimeImmutable('2026-09-09 10:00:00'),
        );
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($expired);

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('passwordSetupTokenRejected');
        $auditLogger->expects(self::never())->method('passwordSetupTokenReplayed');

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, clock: $clock, auditLogger: $auditLogger)->validate(self::CLEAR_TOKEN);
    }

    public function testCompleteHashesThePasswordActivatesTheUserAndConsumesTheToken(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $token = $this->tokenFor($user, $clock);

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($token);
        // La consommation se réclame en base avant toute écriture (issue #356).
        $tokenRepository->expects(self::once())->method('claim')->with($token, $clock->now())->willReturn(TokenClaim::Claimed);
        $tokenRepository->expects(self::once())->method('save')->with($token);

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->expects(self::once())->method('save')->with($user);

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())
            ->method('hashPassword')
            ->with($user, TestCredentials::variant('setup'))
            ->willReturn('hashed-new-password');

        // Journal de sécurité (D5) : `account-activated` pour ce compte, une fois.
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('accountActivated')->with($user);

        $this->service($tokenRepository, $cpgUserRepository, $hasher, $clock, $auditLogger)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));

        self::assertSame('hashed-new-password', $user->getPassword());
        self::assertFalse($user->isPendingActivation());
        self::assertSame('2026-09-10 10:00:00', $user->getActivatedAt()?->format('Y-m-d H:i:s'));
        self::assertFalse($token->isUsable($clock->now()));
        self::assertSame('2026-09-10 10:00:00', $token->getUsedAt()?->format('Y-m-d H:i:s'));
    }

    /**
     * Issue #356 : deux soumissions simultanées du même lien passaient toutes
     * deux la vérification en mémoire, et la seconde imposait son mot de passe
     * sans laisser de trace. La réclamation atomique tranche : la perdante
     * est un rejeu, journalisé comme tel, et rien n'est écrit pour elle.
     */
    public function testCompleteLosingTheClaimIsAReplayAndWritesNothing(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $token = $this->tokenFor($user, $clock);

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($token);
        $tokenRepository->expects(self::once())->method('claim')->willReturn(TokenClaim::AlreadyUsed);
        $tokenRepository->expects(self::never())->method('save');

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->expects(self::never())->method('save');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('passwordSetupTokenReplayed')->with($user);
        $auditLogger->expects(self::never())->method('accountActivated');

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-by-the-loser');

        try {
            $this->service($tokenRepository, $cpgUserRepository, $hasher, $clock, $auditLogger)
                ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
            self::fail('UnusablePasswordSetupLinkException attendue.');
        } catch (UnusablePasswordSetupLinkException) {
            self::assertSame('', $user->getPassword(), 'Le mot de passe de la perdante ne doit pas toucher le compte.');
            self::assertTrue($user->isPendingActivation());
        }
    }

    /**
     * Issue #447 : le hachage du mot de passe prend du temps. La réclamation
     * se fait à l'heure qui le suit, sans quoi un lien qui expire pendant le
     * hachage serait consommé. Le perdant n'est alors pas un rejeu : un lien
     * simplement expiré n'est pas un événement de sécurité.
     */
    public function testALinkThatExpiresWhileThePasswordIsHashedIsNotConsumed(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $token = new PasswordSetupToken($user, hash('sha256', self::CLEAR_TOKEN), $clock->now()->modify('+1 second'));

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($token);
        $tokenRepository->expects(self::once())->method('claim')
            // MockClock est en UTC : deux secondes après le début du hachage.
            ->with($token, new DateTimeImmutable('2026-09-10 10:00:02', new DateTimeZone('UTC')))
            ->willReturn(TokenClaim::Expired);
        $tokenRepository->expects(self::never())->method('save');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('passwordSetupTokenReplayed');
        $auditLogger->expects(self::never())->method('accountActivated');

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturnCallback(static function () use ($clock): string {
            $clock->sleep(2);

            return 'hashed-too-late';
        });

        try {
            $this->service($tokenRepository, hasher: $hasher, clock: $clock, auditLogger: $auditLogger)
                ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
            self::fail('UnusablePasswordSetupLinkException attendue.');
        } catch (UnusablePasswordSetupLinkException) {
            self::assertTrue($user->isPendingActivation());
        }
    }

    /**
     * Issue #458 : sous le verrou, un compte déjà activé (par un autre lien du
     * compte) refuse ce lien sans réclamer le jeton. Un lien présenté pour un
     * compte actif est un rejeu au sens du journal.
     */
    public function testALinkForAnAccountActivatedMeanwhileIsRefusedUnderTheLock(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $token = $this->tokenFor($user, $clock);
        // Activé entre la lecture du jeton et la prise du verrou.
        $user->markActivated(new DateTimeImmutable('2026-09-10 09:59:59'));

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($token);
        $tokenRepository->expects(self::never())->method('claim');
        $tokenRepository->expects(self::never())->method('save');
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('passwordSetupTokenReplayed')->with($user);
        $auditLogger->expects(self::never())->method('accountActivated');

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, clock: $clock, auditLogger: $auditLogger)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
    }

    /**
     * Issue #458 : un compte activé ne garde aucun jeton utilisable.
     */
    public function testTheActivationRemovesTheAccountsUnusedTokens(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $token = $this->tokenFor($user, $clock);

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($token);
        $tokenRepository->method('claim')->willReturn(TokenClaim::Claimed);
        $tokenRepository->expects(self::once())->method('deleteUnusedForUser')->with($user);

        $this->service($tokenRepository, clock: $clock)->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));

        self::assertFalse($user->isPendingActivation());
    }

    /**
     * Seconde passe d'audit de #458 : une relance a remplacé le jeton pendant
     * le hachage. Le lien n'a jamais servi : journalisé comme un lien inconnu
     * (`rejected`), jamais comme un rejeu.
     */
    public function testATokenReplacedWhileThePasswordIsHashedIsUnknownNotReplayed(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($this->tokenFor($user, $clock));
        $tokenRepository->method('claim')->willReturn(TokenClaim::Gone);
        $tokenRepository->expects(self::never())->method('save');
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('passwordSetupTokenReplayed');
        $auditLogger->expects(self::once())->method('passwordSetupTokenRejected');

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, clock: $clock, auditLogger: $auditLogger)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
    }

    /**
     * Un compte supprimé pendant le hachage : journalisé comme un lien
     * inconnu.
     */
    public function testAnAccountDeletedBeforeTheLockIsAnUnknownLink(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($this->tokenFor($user, $clock));
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('passwordSetupTokenRejected');
        $accountLock = self::createStub(AccountLockInterface::class);
        $accountLock->method('whileLocked')->willThrowException(CpgUserNotFoundException::forId($user->getId()));
        $service = new PasswordSetupService($tokenRepository, self::createStub(CpgUserRepositoryInterface::class), self::createStub(UserPasswordHasherInterface::class), $clock, $auditLogger, $accountLock, self::createStub(AccountMcpConnectionsRevokerInterface::class), $this->deadlockRetry());

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $service->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
    }

    /**
     * Le lien d'un compte déjà actif n'ouvre plus le formulaire : `validate()`
     * et `complete()` répondent pareil (seconde passe d'audit de #458).
     */
    public function testValidateRefusesALinkOfAnAccountAlreadyActive(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $user->markActivated(new DateTimeImmutable('2026-09-09 08:00:00'));
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($this->tokenFor($user, $clock));

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, clock: $clock)->validate(self::CLEAR_TOKEN);
    }

    public function testCompleteRejectsAnExpiredTokenWithoutTouchingAnything(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $expired = new PasswordSetupToken(
            new CpgUser('jane', ''),
            hash('sha256', self::CLEAR_TOKEN),
            new DateTimeImmutable('2026-09-01 10:00:00'),
        );

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($expired);
        $tokenRepository->expects(self::never())->method('save');

        $cpgUserRepository = $this->createMock(CpgUserRepositoryInterface::class);
        $cpgUserRepository->expects(self::never())->method('save');

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('accountActivated');

        $this->expectException(UnusablePasswordSetupLinkException::class);

        $this->service($tokenRepository, $cpgUserRepository, clock: $clock, auditLogger: $auditLogger)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
    }

    /**
     * Tâche 5b de #650 : un lien qui **remplace** un mot de passe (compte en
     * attente auquel le backoffice en a posé un, #460) coupe les connexions MCP
     * du compte. Dans l'ordre : sous le verrou, après la réclamation du jeton
     * et l'écriture du mot de passe, `revokeAllOf()` (cause `password-changed`,
     * sur le compte relu) ; le verrou rendu, l'activation est journalisée, puis
     * les lignes de révocation.
     */
    public function testALinkReplacingAPasswordRevokesTheMcpConnectionsInsideTheLockThenAuditsAfterIt(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', 'password-set-by-the-backoffice');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $token = $this->tokenFor($user, $clock);
        $steps = [];
        $revoked = new RevokedAccountMcpConnections(AccountAccessWithdrawal::PasswordChanged, []);

        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($token);
        $tokenRepository->method('claim')->willReturnCallback(static function () use (&$steps): TokenClaim {
            $steps[] = 'claim';

            return TokenClaim::Claimed;
        });

        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::once())->method('revokeAllOf')->with($user, AccountAccessWithdrawal::PasswordChanged)
            ->willReturnCallback(static function () use (&$steps, $user, $revoked): RevokedAccountMcpConnections {
                // Le nouveau mot de passe est déjà écrit sur l'instance, la révocation est dans la fermeture du verrou.
                self::assertSame('hashed-new-password', $user->getPassword());
                $steps[] = 'revoke';

                return $revoked;
            });
        $mcpConnections->expects(self::once())->method('auditRevoked')->with($revoked)
            ->willReturnCallback(static function () use (&$steps): void {
                $steps[] = 'audit-revoked';
            });

        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('accountActivated')->with($user)
            ->willReturnCallback(static function () use (&$steps): void {
                $steps[] = 'activated';
            });

        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-new-password');

        $this->service($tokenRepository, hasher: $hasher, clock: $clock, auditLogger: $auditLogger, mcpConnections: $mcpConnections, accountLock: $this->lockMarkingItsReturn($steps))
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));

        self::assertSame(['claim', 'revoke', 'lock-returned', 'activated', 'audit-revoked'], $steps);
    }

    /**
     * Tâche 5b : le mot de passe **posé** sur un compte qui n'en avait pas (le
     * cas ordinaire d'une invitation) ne révoque rien et n'appelle pas le port :
     * ce compte n'a jamais pu se connecter, donc n'a pu accorder aucune connexion.
     */
    public function testALinkSettingAFirstPasswordNeverCallsThePort(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', CpgUser::NO_PASSWORD);
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($this->tokenFor($user, $clock));
        $tokenRepository->method('claim')->willReturn(TokenClaim::Claimed);

        // untouchedMcpConnections() : jamais revokeAllOf(), jamais auditRevoked().
        $this->service($tokenRepository, clock: $clock)->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));

        self::assertFalse($user->isPendingActivation());
    }

    /**
     * Tâche 5b : une révocation refusée remonte telle quelle (rien n'est journalisé,
     * le verrou réel annule l'activation entière — pinned en HTTP par
     * `AccountWideRevocationTest`).
     */
    public function testARefusedRevocationIsNeitherSwallowedNorAudited(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', 'password-set-by-the-backoffice');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($this->tokenFor($user, $clock));
        $tokenRepository->method('claim')->willReturn(TokenClaim::Claimed);
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->method('revokeAllOf')->willThrowException(new RuntimeException('révocation refusée'));
        $mcpConnections->expects(self::never())->method('auditRevoked');
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('accountActivated');

        $this->expectException(RuntimeException::class);

        $this->service($tokenRepository, clock: $clock, auditLogger: $auditLogger, mcpConnections: $mcpConnections)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
    }

    /**
     * F2 de la relecture de #650 : le lien d'invitation date le changement de
     * mot de passe — que le lien en *pose* un (compte sans mot de passe) ou en
     * *remplace* un — pour que le consentement MCP refuse un cookie d'avant. Il
     * l'écrit sur le compte **relu sous le verrou** (un double que le verrou
     * rend à la place de celui du jeton), à l'instant de la réclamation.
     *
     * @return iterable<string, array{string}>
     */
    public static function passwordsSetByTheLink(): iterable
    {
        yield 'premier mot de passe' => [CpgUser::NO_PASSWORD];
        yield 'mot de passe remplacé' => ['password-set-by-the-backoffice'];
    }

    #[DataProvider('passwordsSetByTheLink')]
    public function testTheLinkRecordsTheInstantOfTheChangeOnTheAccountReadUnderTheLock(string $previousHash): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', $previousHash);
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $token = $this->tokenFor($user, $clock);
        $locked = clone $user;

        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->method('findOneByTokenHash')->willReturn($token);
        $tokenRepository->method('claim')->willReturn(TokenClaim::Claimed);
        $hasher = self::createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed-new-password');
        $accountLock = self::createStub(AccountLockInterface::class);
        $accountLock->method('whileLocked')->willReturnCallback(
            static fn (CpgUser $ignored, Closure $operation): mixed => $operation($locked),
        );
        $mcpConnections = self::createStub(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->method('revokeAllOf')->willReturn(new RevokedAccountMcpConnections(AccountAccessWithdrawal::PasswordChanged, []));

        $this->service($tokenRepository, hasher: $hasher, clock: $clock, mcpConnections: $mcpConnections, accountLock: $accountLock)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));

        self::assertSame('hashed-new-password', $locked->getPassword());
        self::assertEquals($clock->now(), $locked->getCredentialsChangedAt(), 'Le lien n\'a pas daté le changement de mot de passe.');
        self::assertNull($user->getCredentialsChangedAt(), 'L\'instant a été écrit sur le compte d\'avant le verrou.');
        self::assertSame(1, $locked->getCredentialsVersion(), 'Le lien n\'a pas avancé la version des identifiants (#650, S1).');
        self::assertSame(0, $user->getCredentialsVersion(), 'La version a été avancée sur le compte d\'avant le verrou.');
    }

    private function usableToken(MockClock $clock): PasswordSetupToken
    {
        // Un jeton n'existe que pour un compte sur invitation, encore en attente.
        $user = new CpgUser('jane', '');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));

        return $this->tokenFor($user, $clock);
    }

    private function tokenFor(CpgUser $user, MockClock $clock): PasswordSetupToken
    {
        return new PasswordSetupToken($user, hash('sha256', self::CLEAR_TOKEN), $clock->now()->modify('+1 day'));
    }

    private function service(
        PasswordSetupTokenRepositoryInterface $tokenRepository,
        ?CpgUserRepositoryInterface $cpgUserRepository = null,
        ?UserPasswordHasherInterface $hasher = null,
        ?MockClock $clock = null,
        ?SecurityAuditLoggerInterface $auditLogger = null,
        ?AccountMcpConnectionsRevokerInterface $mcpConnections = null,
        ?AccountLockInterface $accountLock = null,
        ?DeadlockRetryInterface $deadlockRetry = null,
    ): PasswordSetupService {
        return new PasswordSetupService(
            $tokenRepository,
            $cpgUserRepository ?? self::createStub(CpgUserRepositoryInterface::class),
            $hasher ?? self::createStub(UserPasswordHasherInterface::class),
            $clock ?? new MockClock(),
            $auditLogger ?? self::createStub(SecurityAuditLoggerInterface::class),
            $accountLock ?? $this->passthroughAccountLock(),
            $mcpConnections ?? $this->untouchedMcpConnections(),
            $deadlockRetry ?? $this->deadlockRetry(),
        );
    }

    /**
     * S3 (CR4) : un lien qui remplace un mot de passe révoque les connexions MCP
     * (#650, 5b) et croise donc, comme le changement de mot de passe, le premier
     * échange d'un code (F1). Interbloqué, il est relancé **une fois**, le jeton
     * **relu** (l'EntityManager remis à neuf a oublié l'ancien, et son compte) ;
     * le hachage n'est pas recalculé et le journal s'écrit une fois.
     */
    public function testADeadlockedLinkReplacingAPasswordIsRetriedOnceOnTheTokenReadAgain(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $staleUser = new CpgUser('jane', 'password-set-by-the-backoffice');
        $staleUser->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $freshUser = clone $staleUser;
        $freshToken = $this->tokenFor($freshUser, $clock);
        $revoked = new RevokedAccountMcpConnections(AccountAccessWithdrawal::PasswordChanged, []);

        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::exactly(2))->method('findOneByTokenHash')->with(hash('sha256', self::CLEAR_TOKEN))
            ->willReturnOnConsecutiveCalls($this->tokenFor($staleUser, $clock), $freshToken);
        $tokenRepository->method('claim')->willReturn(TokenClaim::Claimed);
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::exactly(2))->method('revokeAllOf')->willReturnOnConsecutiveCalls(
            self::throwException(DeadlockedStatement::exception()),
            $revoked,
        );
        $mcpConnections->expects(self::once())->method('auditRevoked')->with($revoked);
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::once())->method('accountActivated')->with($freshUser);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')->willReturn('hashed-new-password');

        $this->service($tokenRepository, hasher: $hasher, clock: $clock, auditLogger: $auditLogger, mcpConnections: $mcpConnections)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));

        self::assertSame('hashed-new-password', $freshUser->getPassword());
    }

    /** Deux interblocages : l'exception remonte, aucun journal. */
    public function testALinkDeadlockedTwiceFailsAndAuditsNothing(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $tokenRepository = self::createStub(PasswordSetupTokenRepositoryInterface::class);
        // Un jeton relu à chaque essai, sur un compte encore en attente : le premier essai, annulé, n'a rien laissé.
        $tokenRepository->method('findOneByTokenHash')->willReturnCallback(function () use ($clock): PasswordSetupToken {
            $user = new CpgUser('jane', 'password-set-by-the-backoffice');
            $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));

            return $this->tokenFor($user, $clock);
        });
        $tokenRepository->method('claim')->willReturn(TokenClaim::Claimed);
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::exactly(2))->method('revokeAllOf')->willThrowException(DeadlockedStatement::exception());
        $mcpConnections->expects(self::never())->method('auditRevoked');
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method('accountActivated');

        $this->expectException(DeadlockException::class);

        $this->service($tokenRepository, clock: $clock, auditLogger: $auditLogger, mcpConnections: $mcpConnections)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
    }

    /** Une autre erreur n'est jamais relancée : le jeton n'est lu qu'une fois. */
    public function testALinkFailingOtherwiseIsNotRetried(): void
    {
        $clock = new MockClock('2026-09-10 10:00:00');
        $user = new CpgUser('jane', 'password-set-by-the-backoffice');
        $user->markInvited(new DateTimeImmutable('2026-09-08 08:00:00'));
        $tokenRepository = $this->createMock(PasswordSetupTokenRepositoryInterface::class);
        $tokenRepository->expects(self::once())->method('findOneByTokenHash')->willReturn($this->tokenFor($user, $clock));
        $tokenRepository->method('claim')->willReturn(TokenClaim::Claimed);
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::once())->method('revokeAllOf')->willThrowException(new RuntimeException('révocation refusée'));

        $this->expectException(RuntimeException::class);

        $this->service($tokenRepository, clock: $clock, mcpConnections: $mcpConnections)
            ->complete(self::CLEAR_TOKEN, TestCredentials::variant('setup'));
    }

    /**
     * Le port des connexions MCP d'un test qui n'en attend aucun appel : un
     * compte sans mot de passe n'a rien à se faire révoquer (tâche 5b de #650).
     */
    private function untouchedMcpConnections(): AccountMcpConnectionsRevokerInterface
    {
        $mcpConnections = $this->createMock(AccountMcpConnectionsRevokerInterface::class);
        $mcpConnections->expects(self::never())->method('revokeAllOf');
        $mcpConnections->expects(self::never())->method('auditRevoked');

        return $mcpConnections;
    }

    /**
     * Comme {@see self::passthroughAccountLock()}, et note `lock-returned` dans
     * `$steps` quand l'opération a rendu la main : ce qui suit est « après le COMMIT ».
     *
     * @param list<string> $steps
     */
    private function lockMarkingItsReturn(array &$steps): AccountLockInterface
    {
        $accountLock = self::createStub(AccountLockInterface::class);
        $accountLock->method('whileLocked')->willReturnCallback(
            static function (CpgUser $user, Closure $operation) use (&$steps): mixed {
                $result = $operation($user);
                $steps[] = 'lock-returned';

                return $result;
            },
        );

        return $accountLock;
    }

    /**
     * Verrou du compte qui exécute l'opération telle quelle : le verrou réel
     * est couvert par PasswordSetupActivationConcurrencyTest, sur la base.
     */
    private function passthroughAccountLock(): AccountLockInterface
    {
        $accountLock = self::createStub(AccountLockInterface::class);
        $accountLock->method('whileLocked')->willReturnCallback(
            static fn (CpgUser $user, Closure $operation): mixed => $operation($user),
        );

        return $accountLock;
    }

    /** La relance réelle, sur un registre qui ne remet rien à neuf : les doubles n'ont pas d'EntityManager. */
    private function deadlockRetry(): DeadlockRetryInterface
    {
        return new DoctrineDeadlockRetry(self::createStub(ManagerRegistry::class), new NullLogger());
    }
}
