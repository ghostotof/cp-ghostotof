<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\Doctrine;

use App\Security\User\Infrastructure\Doctrine\DoctrineDeadlockRetry;
use App\Tests\Support\DeadlockedStatement;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * La relance unique sur interblocage (S3 de la seconde relecture de #650, CR4).
 *
 * Quatre issues : un premier essai qui aboutit n'est ni relancé ni journalisé ;
 * un interblocage (`40P01`) est relancé **une fois**, sur un EntityManager remis
 * à neuf **avant** le second essai ; un second interblocage remonte tel quel
 * (le second, pas le premier) ; toute autre exception — y compris une autre
 * `DriverException` — remonte au premier essai, sans réinitialisation.
 *
 * Le rôle de la remise à neuf ne se voit qu'en base (la fermeture de
 * l'EntityManager par la transaction annulée) : `AccountWideRevocationDeadlockTest`
 * la prouve en processus réels.
 */
final class DoctrineDeadlockRetryTest extends TestCase
{
    public function testAFirstAttemptThatSucceedsIsNeitherRetriedNorLogged(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('resetManager');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $attempts = [];
        $expected = new stdClass();

        $result = new DoctrineDeadlockRetry($registry, $logger)->retryOnce(static function (bool $isRetry) use (&$attempts, $expected): stdClass {
            $attempts[] = $isRetry;

            return $expected;
        });

        self::assertSame($expected, $result);
        self::assertSame([false], $attempts);
    }

    public function testADeadlockedFirstAttemptIsRetriedOnceOnAFreshEntityManager(): void
    {
        $steps = [];
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager')->willReturnCallback(static function () use (&$steps): ObjectManager {
            $steps[] = 'reset';

            return self::createStub(ObjectManager::class);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            self::stringContains('relancé'),
            self::callback(static fn (array $context): bool => 'deadlock-retried' === ($context['event'] ?? null) && DeadlockedStatement::SQLSTATE === ($context['sqlState'] ?? null)),
        );

        $expected = new stdClass();

        $result = new DoctrineDeadlockRetry($registry, $logger)->retryOnce(static function (bool $isRetry) use (&$steps, $expected): stdClass {
            $steps[] = $isRetry ? 'second attempt' : 'first attempt';

            return $isRetry ? $expected : throw DeadlockedStatement::exception();
        });

        self::assertSame($expected, $result);
        self::assertSame(['first attempt', 'reset', 'second attempt'], $steps, 'Le second essai doit suivre la remise à neuf de l\'EntityManager.');
    }

    public function testASecondDeadlockIsNotRetriedAgainAndTheSecondOneSurfaces(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $second = DeadlockedStatement::exception();
        $attempts = [];

        $surfaced = null;
        try {
            new DoctrineDeadlockRetry($registry, self::createStub(LoggerInterface::class))->retryOnce(
                static function (bool $isRetry) use (&$attempts, $second): string {
                    $attempts[] = $isRetry;

                    throw $isRetry ? $second : DeadlockedStatement::exception();
                },
            );
        } catch (DeadlockException $deadlock) {
            $surfaced = $deadlock;
        }

        self::assertSame($second, $surfaced, 'Le second interblocage aurait dû remonter.');

        self::assertSame([false, true], $attempts, 'Exactement deux essais.');
    }

    public function testAnyOtherExceptionIsNotRetried(): void
    {
        foreach ($this->otherFailures() as $label => $failure) {
            $registry = $this->createMock(ManagerRegistry::class);
            $registry->expects(self::never())->method('resetManager');
            $attempts = 0;

            $surfaced = null;
            try {
                new DoctrineDeadlockRetry($registry, self::createStub(LoggerInterface::class))->retryOnce(
                    static function () use (&$attempts, $failure): string {
                        ++$attempts;

                        throw $failure;
                    },
                );
            } catch (RuntimeException|DriverException $exception) {
                $surfaced = $exception;
            }

            self::assertSame($failure, $surfaced, $label.' aurait dû remonter.');

            self::assertSame(1, $attempts, $label.' a été relancée.');
        }
    }

    /**
     * Un refus ordinaire, et une autre erreur de la base (l'attente d'un verrou
     * dépassée, `55P03`) : seule `DeadlockException` se relance.
     *
     * @return array<string, RuntimeException|DriverException>
     */
    private function otherFailures(): array
    {
        return [
            'une exception ordinaire' => new RuntimeException('refus'),
            'une autre erreur de la base' => DeadlockedStatement::otherDriverException(),
        ];
    }
}
