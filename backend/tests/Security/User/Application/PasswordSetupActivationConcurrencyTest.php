<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\UnusablePasswordSetupLinkException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\ChildPhpProcess;
use App\Tests\Support\FlushInterleaving;
use App\Tests\Support\InvitesUsers;
use App\Tests\Support\ProcessBarrier;
use App\Tests\Support\ReadsSecurityAuditLog;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Un compte activé ne garde aucun jeton utilisable (issue #458).
 *
 * L'activation (`PasswordSetupService::complete()`) ne prenait pas le verrou
 * du compte que pose le handler d'invitation (#435) : un handler qui passait
 * entre la réclamation du jeton et l'écriture de l'activation voyait le compte
 * encore en attente et émettait un nouveau jeton, valable 48 h sur un compte
 * actif — assez pour en redéfinir le mot de passe. L'entrelacement, sur le
 * modèle de SendAccountInvitationConcurrencyTest :
 *
 *  1. B (Fixtures/run-invitation-handler.php) attend la barrière ;
 *  2. A, dans ce processus, active le compte ; au `preFlush` de l'activation
 *     — jeton réclamé, activation pas encore écrite —, le test relâche B ;
 *  3. le test attend que B ait **fini** ou soit **bloqué sur un verrou** ;
 *  4. une fois B terminé, le test compte les jetons non utilisés du compte,
 *     puis rejoue le lien consommé.
 *
 * La purge des jetons non utilisés à l'activation suffit à n'en laisser
 * aucun. Le verrou garantit en plus que le handler attend au lieu de supprimer
 * le jeton déjà réclamé : sans lui, le rejeu du lien passait pour un lien
 * inconnu, et le journal perdait l'événement `replayed`. La réponse, elle,
 * est la même dans les deux cas (issue #362) : seul le journal les distingue.
 */
final class PasswordSetupActivationConcurrencyTest extends KernelTestCase
{
    use InvitesUsers;
    use ReadsSecurityAuditLog;

    private const int TIMEOUT_SECONDS = 30;

    private const string HANDLER_SCRIPT = __DIR__.'/../Fixtures/run-invitation-handler.php';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnInvitationHandledDuringTheActivationLeavesNoUsableToken(): void
    {
        $clearToken = $this->inviteAndCollectSetupToken('newcomer@example.com', Locale::FR);
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByEmail('newcomer@example.com');
        self::assertInstanceOf(CpgUser::class, $user);
        $userId = $user->getId()->toRfc4122();
        $generation = $user->getInvitationGeneration();
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        $concurrent = $this->interleaveBeforeNextFlush($userId, $generation);
        self::getContainer()->get(PasswordSetupServiceInterface::class)->complete($clearToken, TestCredentials::variant('activation'));
        $concurrent->wait();
        self::assertSame(0, $concurrent->getExitCode(), $concurrent->getOutput().$concurrent->getErrorOutput());

        self::assertTrue($this->isActivated($userId));
        self::assertSame(0, $this->unusedTokenCountOf($userId), 'Un compte activé garde un jeton utilisable (#458).');

        // Ce que seul le verrou garantit : le handler a attendu l'activation
        // au lieu de supprimer le jeton déjà réclamé. Le lien consommé reste
        // connu, et son rejeu est journalisé comme tel (`replayed`), pas pris
        // pour un lien inconnu (`rejected`).
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        try {
            self::getContainer()->get(PasswordSetupServiceInterface::class)->complete($clearToken, TestCredentials::variant('rejeu'));
            self::fail('UnusablePasswordSetupLinkException attendue au rejeu du lien.');
        } catch (UnusablePasswordSetupLinkException) {
            self::assertCount(1, self::securityAuditEvents('password-setup-token-replayed'));
            self::assertSame([], self::securityAuditEvents('password-setup-token-rejected'));
        }
    }

    /**
     * Démarre B et le relâche au prochain `preFlush` ({@see FlushInterleaving}).
     */
    private function interleaveBeforeNextFlush(string $userId, int $generation): Process
    {
        $barrier = ProcessBarrier::hold('activation-barrier-');
        $concurrent = ChildPhpProcess::inTestEnvironment(self::TIMEOUT_SECONDS, self::HANDLER_SCRIPT, $barrier->path(), $userId, (string) $generation);
        $concurrent->start();
        FlushInterleaving::releaseBeforeNextFlush(self::getContainer()->get(EntityManagerInterface::class), $barrier, $concurrent, self::TIMEOUT_SECONDS);

        return $concurrent;
    }

    private function isActivated(string $userId): bool
    {
        return null !== self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT activated_at FROM cpg_user WHERE id = :id',
            ['id' => $userId],
        );
    }

    private function unusedTokenCountOf(string $userId): int
    {
        $count = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM password_setup_token WHERE user_id = :user AND used_at IS NULL',
            ['user' => $userId],
        );
        self::assertIsInt($count);

        return $count;
    }

    private function purge(): void
    {
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement('DELETE FROM password_setup_token');
        $connection->executeStatement('DELETE FROM cpg_user');
    }
}
