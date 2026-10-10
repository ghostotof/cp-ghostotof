<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\Command;

use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

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
            '--password' => TestCredentials::plainPassword(),
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('jane', $tester->getDisplay());

        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane');
        self::assertNotNull($user);
    }

    public function testFailsWhenUsernameAlreadyUsed(): void
    {
        $tester = $this->commandTester();
        $tester->execute(['--username' => 'jane', '--password' => TestCredentials::plainPassword()]);

        $exitCode = $tester->execute(['--username' => 'jane', '--password' => TestCredentials::variant('autre')]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
    }

    public function testFailsOnInvalidUsername(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--username' => 'ab', '--password' => TestCredentials::plainPassword()]);

        self::assertSame(1, $exitCode);
    }

    public function testFailsOnPasswordTooLong(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--username' => 'jane',
            '--password' => str_repeat('a', 4097),
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('dépasser', $tester->getDisplay());
    }

    public function testCreatesUserWithRoleSuper(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--username' => 'super',
            '--password' => TestCredentials::plainPassword(),
            '--role' => ['ROLE_SUPER'],
        ]);

        self::assertSame(0, $exitCode);

        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('super');
        self::assertNotNull($user);
        self::assertContains('ROLE_SUPER', $user->getRoles());
    }

    /**
     * ADR 0003, Task 12 (#56) : ROLE_TRUSTED s'obtient nominativement par
     * invitation (lié à une adresse e-mail), jamais depuis la CLI — un compte
     * CLI n'a qu'un username et ne dirait pas à qui le CV a été ouvert. Ce test
     * pince la décision : élargir ALLOWED_ROLES doit le faire rougir.
     */
    public function testRefusesRoleTrustedWhichIsGrantedByInvitationOnly(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--username' => 'jane',
            '--password' => TestCredentials::plainPassword(),
            '--role' => ['ROLE_TRUSTED'],
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ROLE_TRUSTED', $tester->getDisplay());
        self::assertNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane'));
    }

    public function testFailsOnUnknownRole(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute([
            '--username' => 'jane',
            '--password' => TestCredentials::plainPassword(),
            '--role' => ['ROLE_UNKNOWN'],
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('inconnu', $tester->getDisplay());
    }

    /**
     * Issue #383 : sans --username, `ask()` rend en non interactif la valeur
     * par défaut (null) sans passer par le validateur. La commande tombait sur
     * un `assert()` en dev, sur un TypeError en prod ; elle doit refuser en
     * nommant l'option à passer.
     */
    public function testANonInteractiveRunWithoutUsernameFailsAndNamesTheOption(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--password' => TestCredentials::plainPassword()], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--username', $tester->getDisplay());
    }

    public function testANonInteractiveRunWithoutPasswordFailsAndNamesTheOption(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--username' => 'jane'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--password', $tester->getDisplay());
        self::assertNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane'));
    }

    /**
     * Issue #383 : une confirmation différente sortait de la commande en
     * exception non rattrapée ; elle échoue désormais comme toute autre saisie
     * refusée, par un message et le code 1.
     */
    public function testAPasswordConfirmationThatDiffersFailsWithAMessage(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs([TestCredentials::plainPassword(), TestCredentials::variant('autre')]);

        $exitCode = $tester->execute(['--username' => 'jane'], ['interactive' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ne correspondent pas', $this->normalizedDisplay($tester));
        self::assertNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane'));
    }

    /**
     * Le validateur de la question lève l'exception du domaine
     * (InvalidUsernameException) : le QuestionHelper en affiche le message et
     * redemande la saisie.
     */
    public function testTheInteractivePromptAsksAgainWhenTheUsernameIsInvalid(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['ab', 'jane', TestCredentials::plainPassword(), TestCredentials::plainPassword()]);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('"ab" est invalide', $this->normalizedDisplay($tester));
        self::assertNotNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane'));
    }

    public function testTheInteractivePromptAsksAgainWhenThePasswordIsEmpty(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['', TestCredentials::plainPassword(), TestCredentials::plainPassword()]);

        $exitCode = $tester->execute(['--username' => 'jane'], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('ne peut pas être vide', $this->normalizedDisplay($tester));
        self::assertNotNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane'));
    }

    /** SymfonyStyle replie les blocs d'erreur à la largeur du terminal. */
    private function normalizedDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        \assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);

        // find() renvoie un LazyCommand (proxy) plutôt que CreateCpgUserCommand
        // directement : CommandTester s'en accommode, il délègue à l'instance réelle.
        return new CommandTester($application->find('app:user:create'));
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
