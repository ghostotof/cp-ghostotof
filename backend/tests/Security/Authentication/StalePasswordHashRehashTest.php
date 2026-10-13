<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\HttpJson;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Issue #590 : après un changement de `password_hashers`, un compte au hachage
 * ancien répond à un mauvais mot de passe en un temps à lui (la dérivation se
 * fait au coût stocké). C'est une limite acceptée parce qu'elle se résorbe à
 * la connexion réussie suivante : `PasswordMigratingListener` rehache au coût
 * configuré et `CpgUserRepository::upgradePassword()` l'enregistre. Ce test
 * pince ce rattrapage, dont la limite acceptée dépend — un badge retiré, un
 * fournisseur changé le feraient disparaître sans bruit.
 */
final class StalePasswordHashRehashTest extends WebTestCase
{
    use HttpJson;

    private const string USERNAME = 'jane';

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testASuccessfulLoginRehashesAStaleHashAtTheConfiguredCost(): void
    {
        $client = self::createClient();
        $client->getContainer()->get(CpgUserRegistrarInterface::class)->register(self::USERNAME, TestCredentials::plainPassword(), [CpgUser::ROLE_TRUSTED]);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();

        // Le hachage d'une configuration précédente : bcrypt au coût 5, quand
        // `when@test` configure 4.
        $staleHash = password_hash(TestCredentials::plainPassword(), \PASSWORD_BCRYPT, ['cost' => 5]);
        $connection->executeStatement('UPDATE cpg_user SET password = :hash WHERE username = :username', ['hash' => $staleHash, 'username' => self::USERNAME]);
        // Le compte que l'inscription a laissé dans la carte d'identité garde
        // son hachage frais : sans ce vidage, le login le relirait de là.
        $entityManager->clear();

        $client->request('POST', '/api/login_check', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'fetch'], content: self::jsonBody([
            'username' => self::USERNAME,
            'password' => TestCredentials::plainPassword(),
        ]));
        self::assertResponseIsSuccessful();

        $storedHash = $connection->fetchOne('SELECT password FROM cpg_user WHERE username = :username', ['username' => self::USERNAME]);
        self::assertIsString($storedHash);
        self::assertStringStartsWith('$2y$04$', $storedHash, 'La connexion réussie n\'a pas rehaché le mot de passe au coût configuré.');
        self::assertTrue(password_verify(TestCredentials::plainPassword(), $storedHash));
    }
}
