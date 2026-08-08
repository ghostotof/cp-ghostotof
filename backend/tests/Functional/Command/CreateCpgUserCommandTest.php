<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Repository\CpgUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CreateCpgUserCommandTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM cpg_user');
    }

    protected function tearDown(): void
    {
        $this->getEntityManager()->getConnection()->executeStatement('DELETE FROM cpg_user');
        parent::tearDown();
    }

    public function testCreatesUserWithGivenCredentials(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--username' => 'jane',
            '--password' => 'SecurePassword123',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('jane', $tester->getDisplay());

        $user = $this->getContainer()->get(CpgUserRepository::class)->findOneByUsername('jane');
        self::assertNotNull($user);
    }

    public function testFailsWhenUsernameAlreadyUsed(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['--username' => 'jane', '--password' => 'SecurePassword123']);

        $exitCode = $tester->execute(['--username' => 'jane', '--password' => 'AnotherPassword123']);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
    }

    public function testFailsOnInvalidUsername(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--username' => 'ab', '--password' => 'SecurePassword123']);

        self::assertSame(1, $exitCode);
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);

        // find() renvoie un LazyCommand (proxy) plutôt que CreateCpgUserCommand
        // directement : CommandTester s'en accommode, il délègue à l'instance réelle.
        return new CommandTester($application->find('app:user:create'));
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return $this->getContainer()->get(EntityManagerInterface::class);
    }
}
