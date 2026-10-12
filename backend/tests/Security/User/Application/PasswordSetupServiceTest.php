<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\AccountLockInterface;
use App\Security\User\Application\PasswordSetupService;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use App\Security\User\Domain\Exception\UnusablePasswordSetupLinkException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Domain\ValueObject\TokenClaim;
use App\Tests\Support\TestCredentials;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
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
        $service = new PasswordSetupService($tokenRepository, self::createStub(CpgUserRepositoryInterface::class), self::createStub(UserPasswordHasherInterface::class), $clock, $auditLogger, $accountLock);

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

    private function usableToken(MockClock $clock): PasswordSetupToken
    {
        // Un jeton n'existe que pour un compte invité, encore en attente.
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
    ): PasswordSetupService {
        return new PasswordSetupService(
            $tokenRepository,
            $cpgUserRepository ?? self::createStub(CpgUserRepositoryInterface::class),
            $hasher ?? self::createStub(UserPasswordHasherInterface::class),
            $clock ?? new MockClock(),
            $auditLogger ?? self::createStub(SecurityAuditLoggerInterface::class),
            $this->passthroughAccountLock(),
        );
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
}
