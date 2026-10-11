<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

/**
 * Une erreur de PostgreSQL ne cite aucune valeur des données (issue #428).
 *
 * Le serveur recopie la valeur en cause dans son message : la clé en conflit
 * (`DETAIL: Key (email)=(personne@exemple.fr) already exists`), la ligne
 * entière d'une contrainte violée (`DETAIL: Failing row contains (…)`), la
 * saisie refusée par un type (`invalid input syntax for type uuid: "…"`).
 * Le noyau comme le worker journalisent toute la chaîne `previous`, jusque
 * dans l'ErrorDetailsStamp du failure_transport. La rédaction se fait au
 * pilote, pour toutes les tables ; la classe DBAL et le SQLSTATE, que des
 * appelants lisent (CpgUserInviter attrape la violation d'unicité), restent.
 *
 * Base `_test` réelle : c'est le message du vrai serveur qui est vérifié.
 */
final class DriverErrorRedactionMiddlewareTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testAUniqueViolationQuotesNeitherTheDigestNorTheEmail(): void
    {
        $user = $this->persistUser('jane', 'sentinel.jane@example.com');
        $hash = hash('sha256', 'clear-jane');
        $this->em->persist(new PasswordSetupToken($user, $hash, new DateTimeImmutable('+48 hours')));
        $this->em->flush();

        $tokenFailure = $this->failureOf(fn (): int|string => $this->em->getConnection()->executeStatement(
            'INSERT INTO password_setup_token (id, token_hash, expires_at, user_id) VALUES (gen_random_uuid(), :hash, NOW(), :user)',
            ['hash' => $hash, 'user' => $user->getId()->toRfc4122()],
        ));
        $emailFailure = $this->failureOf(fn (): int|string => $this->em->getConnection()->executeStatement(
            'UPDATE cpg_user SET email = :email WHERE username = :username',
            ['email' => 'sentinel.jane@example.com', 'username' => $this->persistUser('john', null)->getUsername()],
        ));

        foreach ([[$tokenFailure, $hash], [$emailFailure, 'sentinel.jane']] as [$failure, $value]) {
            self::assertInstanceOf(UniqueConstraintViolationException::class, $failure);
            self::assertSame('23505', $failure->getSQLState());
            self::assertStringContainsString('duplicate key value violates unique constraint', $failure->getMessage());
            self::assertStringNotContainsString($value, $this->chainAsText($failure));
        }
    }

    /** `Failing row contains (…)` : la ligne entière, condensat du mot de passe compris. */
    public function testAFailingRowIsNotQuoted(): void
    {
        $failure = $this->failureOf(fn (): int|string => $this->em->getConnection()->executeStatement(
            "INSERT INTO cpg_user (id, username, password, roles, email) VALUES (gen_random_uuid(), 'sentinel-row', NULL, '[]', 'sentinel.row@example.com')",
        ));

        self::assertInstanceOf(NotNullConstraintViolationException::class, $failure);
        self::assertStringNotContainsString('sentinel', $this->chainAsText($failure));
    }

    public function testAValueRefusedByItsTypeIsNotQuoted(): void
    {
        $failure = $this->failureOf(fn (): mixed => $this->em->getConnection()->fetchOne(
            'SELECT id FROM cpg_user WHERE id = :id',
            ['id' => 'sentinel-not-a-uuid'],
        ));

        self::assertSame('22P02', $failure->getSQLState());
        self::assertStringContainsString('invalid input syntax for type uuid', $failure->getMessage());
        self::assertStringNotContainsString('sentinel', $this->chainAsText($failure));
    }

    /**
     * @param callable(): mixed $statement
     */
    private function failureOf(callable $statement): DriverException
    {
        try {
            $statement();
        } catch (DriverException $exception) {
            return $exception;
        }

        self::fail('La requête aurait dû échouer.');
    }

    /**
     * Les messages de toute la chaîne `previous`, que le noyau et le worker
     * journalisent. Les arguments des traces, eux, relèvent de
     * `zend.exception_ignore_args` (issue #420) : la requête et ses
     * paramètres y figureraient de toute façon.
     */
    private function chainAsText(Throwable $failure): string
    {
        $text = '';
        for ($link = $failure; null !== $link; $link = $link->getPrevious()) {
            $text .= $link->getMessage()."\n";
        }

        return $text;
    }

    private function persistUser(string $username, ?string $email): CpgUser
    {
        $user = new CpgUser($username, 'hashed-password');
        if (null !== $email) {
            $user->setEmail($email);
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function purge(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM password_setup_token');
        $this->em->getConnection()->executeStatement('DELETE FROM cpg_user');
        $this->em->clear();
    }
}
