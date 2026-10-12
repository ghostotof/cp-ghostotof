<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\CpgUserInviterInterface;
use App\Security\User\Infrastructure\Doctrine\UsernameAllocationLockInsideTransactionException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Throwable;

/**
 * Le scénario de l'issue #532, au niveau du cas d'usage, sur la base réelle.
 *
 * `invite()` publie l'invitation au retour du verrou d'attribution, qu'il
 * tient pour le COMMIT. Appelé dans une transaction déjà ouverte, ce retour
 * n'était qu'un `RELEASE SAVEPOINT` : le message partait vers le worker
 * avant que le compte soit visible, le handler n'en trouvait aucun et
 * abandonnait le message sans réessai — une invitation perdue sans bruit.
 * Désormais rien n'est enregistré ni publié : le verrou refuse sous son nom.
 */
final class InviteInsideTransactionTest extends KernelTestCase
{
    private const string EMAIL = 'nested-invite@example.com';

    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAnInvitationInsideATransactionAlreadyOpenIsRefusedAndPublishesNothing(): void
    {
        $this->connection->beginTransaction();
        $caught = null;

        try {
            self::getContainer()->get(CpgUserInviterInterface::class)->invite(self::EMAIL, Locale::FR);
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            $this->connection->rollBack();
        }

        self::assertInstanceOf(UsernameAllocationLockInsideTransactionException::class, $caught, \sprintf("L'invitation imbriquée n'a pas été refusée sous son nom : %s.", get_debug_type($caught)));
        self::assertSame([], $this->asyncTransport()->getSent(), "L'invitation a été publiée avant la validation du compte.");
        self::assertSame(0, $this->connection->fetchOne('SELECT COUNT(*) FROM cpg_user WHERE email = ?', [self::EMAIL]));
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function purge(): void
    {
        $this->connection->executeStatement('DELETE FROM cpg_user WHERE email = ?', [self::EMAIL]);
    }
}
