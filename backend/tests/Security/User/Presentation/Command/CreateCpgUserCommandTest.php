<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\Command;

use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Tests\Support\TestCredentials;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => 'jane'], TestCredentials::plainPassword());

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('jane', $tester->getDisplay());

        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane');
        self::assertNotNull($user);
    }

    public function testFailsWhenUsernameAlreadyUsed(): void
    {
        $tester = $this->commandTester();
        $this->executeWithPasswordOnStdin($tester, ['--username' => 'jane'], TestCredentials::plainPassword());

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => 'jane'], TestCredentials::variant('autre'));

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('existe déjà', $tester->getDisplay());
    }

    public function testFailsOnInvalidUsername(): void
    {
        $tester = $this->commandTester();

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => 'ab'], TestCredentials::plainPassword());

        self::assertSame(1, $exitCode);
    }

    /**
     * Issue #386 : `--username` n'est pas rogné, contrairement à l'invite. Un
     * retour à la ligne final y créait un compte homonyme de `jane`.
     */
    public function testRefusesAUsernameEndingWithALineFeed(): void
    {
        $tester = $this->commandTester();

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => "jane\n"], TestCredentials::plainPassword());

        self::assertSame(1, $exitCode);
        self::assertNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername("jane\n"));
    }

    public function testFailsOnPasswordTooLong(): void
    {
        $tester = $this->commandTester();

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => 'jane'], str_repeat('a', 4097));

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('dépasser', $tester->getDisplay());
    }

    public function testCreatesUserWithRoleSuper(): void
    {
        $tester = $this->commandTester();

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => 'super', '--role' => ['ROLE_SUPER']], TestCredentials::plainPassword());

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

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => 'jane', '--role' => ['ROLE_TRUSTED']], TestCredentials::plainPassword());

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ROLE_TRUSTED', $tester->getDisplay());
        self::assertNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane'));
    }

    public function testFailsOnUnknownRole(): void
    {
        $tester = $this->commandTester();

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => 'jane', '--role' => ['ROLE_UNKNOWN']], TestCredentials::plainPassword());

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

        $tester->setInputs([TestCredentials::plainPassword()]);

        $exitCode = $tester->execute(['--password-stdin' => true], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--username', $tester->getDisplay());
    }

    public function testANonInteractiveRunWithoutPasswordFailsAndNamesTheOption(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--username' => 'jane'], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--password-stdin', $tester->getDisplay());
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
     * redemande la saisie. Le message ne cite pas la saisie, qui peut être
     * n'importe quoi — un mot de passe collé au mauvais endroit compris.
     */
    public function testTheInteractivePromptAsksAgainWhenTheUsernameIsInvalid(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['ab', 'jane', TestCredentials::plainPassword(), TestCredentials::plainPassword()]);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        $display = $this->normalizedDisplay($tester);
        self::assertStringContainsString('Le nom d\'utilisateur est invalide', $display);
        self::assertStringNotContainsString('"ab"', $display);
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

    /**
     * Revue de #383 : sur une fin d'entrée, le QuestionHelper relance la
     * dernière erreur du validateur. Elle quittait la commande, et le
     * ErrorListener de la console la journalisait en `critical`, saisie
     * comprise : un mot de passe collé dans le champ du nom finissait en clair
     * dans les journaux.
     */
    public function testARefusedUsernameFollowedByTheEndOfInputFailsWithoutQuotingIt(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['MyS3cr3t!Pass']);

        $exitCode = $tester->execute([], ['interactive' => true]);

        self::assertSame(1, $exitCode);
        $display = $this->normalizedDisplay($tester);
        self::assertStringContainsString('Le nom d\'utilisateur est invalide', $display);
        self::assertStringNotContainsString('MyS3cr3t!Pass', $display);
    }

    public function testAnEmptyPasswordFollowedByTheEndOfInputFailsWithAMessage(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs(['']);

        $exitCode = $tester->execute(['--username' => 'jane'], ['interactive' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('ne peut pas être vide', $this->normalizedDisplay($tester));
        self::assertNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane'));
    }

    /**
     * Revue de #383 : une question est rognée par défaut, alors que
     * `--password` et json_login prennent le mot de passe tel quel. Un
     * mot de passe saisi avec une espace en tête ou en fin donnait un compte
     * dont on ne pouvait pas se servir.
     */
    public function testAPasswordTypedAtThePromptKeepsItsSurroundingSpaces(): void
    {
        $password = '  '.TestCredentials::plainPassword().'  ';
        $tester = $this->commandTester();
        $tester->setInputs([$password, $password]);

        $exitCode = $tester->execute(['--username' => 'jane'], ['interactive' => true]);

        self::assertSame(0, $exitCode);
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane');
        self::assertNotNull($user);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, $password));
    }

    /**
     * Issue #386 : un mot de passe passé en argument se lit dans `ps`, dans
     * l'historique du shell et dans le contexte `command` des journaux du
     * ErrorListener de la console. L'option n'existe plus.
     */
    public function testThePasswordCannotBePassedAsAnArgumentAnyMore(): void
    {
        $tester = $this->commandTester();

        $this->expectException(InvalidOptionException::class);

        $tester->execute(['--username' => 'jane', '--password' => TestCredentials::plainPassword()], ['interactive' => false]);
    }

    /**
     * La fin de ligne qu'ajoutent `echo` ou un heredoc est retirée, et elle
     * seule : les espaces autour restent, comme à l'invite et dans json_login.
     */
    public function testReadsThePasswordFromStandardInputWithoutItsLineEnding(): void
    {
        $password = ' '.TestCredentials::plainPassword().' ';
        $tester = $this->commandTester();

        $exitCode = $this->executeWithPasswordOnStdin($tester, ['--username' => 'jane'], $password);

        self::assertSame(0, $exitCode);
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane');
        self::assertNotNull($user);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $password));
    }

    public function testAWindowsLineEndingOnStandardInputIsRemovedToo(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs([TestCredentials::plainPassword()."\r"]);

        $exitCode = $tester->execute(['--username' => 'jane', '--password-stdin' => true], ['interactive' => false]);

        self::assertSame(0, $exitCode);
        $user = self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane');
        self::assertNotNull($user);
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, TestCredentials::plainPassword()));
    }

    /**
     * L'entrée standard porte le mot de passe : une invite du nom
     * d'utilisateur la lirait à sa place. Même règle que `docker login`.
     */
    public function testPasswordStdinRequiresTheUsernameOption(): void
    {
        $tester = $this->commandTester();
        $tester->setInputs([TestCredentials::plainPassword()]);

        $exitCode = $tester->execute(['--password-stdin' => true], ['interactive' => true]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--username', $tester->getDisplay());
        self::assertCount(0, self::getContainer()->get(CpgUserRepositoryInterface::class)->findAll());
    }

    public function testAnEmptyStandardInputFails(): void
    {
        $tester = $this->commandTester();

        $exitCode = $tester->execute(['--username' => 'jane', '--password-stdin' => true], ['interactive' => false]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('entrée standard', $this->normalizedDisplay($tester));
        self::assertNull(self::getContainer()->get(CpgUserRepositoryInterface::class)->findOneByUsername('jane'));
    }

    /** SymfonyStyle replie les blocs d'erreur à la largeur du terminal. */
    private function normalizedDisplay(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * Le mot de passe passe par l'entrée standard, suivi de la fin de ligne
     * qu'y met `setInputs()` comme le ferait `echo`.
     *
     * @param array<string, mixed> $arguments
     */
    private function executeWithPasswordOnStdin(CommandTester $tester, array $arguments, string $password): int
    {
        $tester->setInputs([$password]);

        return $tester->execute([...$arguments, '--password-stdin' => true], ['interactive' => false]);
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
