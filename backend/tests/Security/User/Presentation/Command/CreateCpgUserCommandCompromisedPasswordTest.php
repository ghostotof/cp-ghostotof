<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\Command;

use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Presentation\Command\CreateCpgUserCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Point d'audit B8 : la commande de création d'utilisateur refuse un mot de
 * passe compromis, au même titre que l'endpoint backoffice. Test isolé (pas
 * de kernel) : l'environnement de test désactive globalement
 * NotCompromisedPassword (validator.yaml, when@test), on injecte donc ici un
 * ValidatorInterface qui simule un mot de passe reconnu comme compromis.
 */
final class CreateCpgUserCommandCompromisedPasswordTest extends TestCase
{
    public function testFailsWhenPasswordIsCompromised(): void
    {
        $registrar = $this->createMock(CpgUserRegistrarInterface::class);
        $registrar->expects(self::never())->method('register');

        // La longueur passe par le même validateur (issue #386) : seule la
        // contrainte de fuite renvoie une violation.
        $validator = self::createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(static fn (mixed $value, mixed $constraints): ConstraintViolationList => $constraints instanceof NotCompromisedPassword
            ? new ConstraintViolationList([new ConstraintViolation('This password has been leaked in a data breach.', null, [], '', null, 'hunter2')])
            : new ConstraintViolationList());

        $tester = new CommandTester(new CreateCpgUserCommand($registrar, $validator));

        $tester->setInputs(['hunter2-but-long-enough']);
        $exitCode = $tester->execute(['--username' => 'jane', '--password-stdin' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('fuite de données', $tester->getDisplay());
    }

    public function testSucceedsWhenValidatorReportsNoViolation(): void
    {
        $registrar = $this->createMock(CpgUserRegistrarInterface::class);
        $registrar->expects(self::once())
            ->method('register')
            ->willReturn(new CpgUser('jane', 'hashed'));

        $validator = self::createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $tester = new CommandTester(new CreateCpgUserCommand($registrar, $validator));

        $tester->setInputs(['a-fresh-strong-password']);
        $exitCode = $tester->execute(['--username' => 'jane', '--password-stdin' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    /**
     * Garde-fou : la commande valide bien la contrainte NotCompromisedPassword
     * (et non une autre), pour que la désactivation en test porte sur le bon
     * contrôle.
     */
    public function testValidatesAgainstNotCompromisedPasswordConstraint(): void
    {
        $registrar = self::createStub(CpgUserRegistrarInterface::class);
        $registrar->method('register')->willReturn(new CpgUser('jane', 'hashed'));

        $validated = [];
        $validator = self::createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(static function (mixed $value, mixed $constraints) use (&$validated): ConstraintViolationList {
            $validated[] = $constraints;

            return new ConstraintViolationList();
        });

        $tester = new CommandTester(new CreateCpgUserCommand($registrar, $validator));
        $tester->setInputs(['a-fresh-strong-password']);
        $tester->execute(['--username' => 'jane', '--password-stdin' => true], ['interactive' => false]);

        self::assertCount(1, array_filter($validated, static fn (mixed $constraint): bool => $constraint instanceof NotCompromisedPassword));
    }

    /**
     * Revue de #386 : un rôle refusé se sait dès les options. Le refuser
     * après la lecture du mot de passe et l'appel à haveibeenpwned faisait
     * circuler le secret pour rien.
     */
    public function testAnUnknownRoleIsRefusedBeforeThePasswordIsEvenValidated(): void
    {
        $registrar = $this->createMock(CpgUserRegistrarInterface::class);
        $registrar->expects(self::never())->method('register');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::never())->method('validate');

        $tester = new CommandTester(new CreateCpgUserCommand($registrar, $validator));
        $tester->setInputs(['a-fresh-strong-password']);
        $exitCode = $tester->execute(['--username' => 'jane', '--password-stdin' => true, '--role' => ['ROLE_TRUSTED']], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('ROLE_TRUSTED', $tester->getDisplay());
    }

    /**
     * Revue de #386 : `NotCompromisedPassword` sans `skipOnError` relance
     * l'exception du client HTTP quand haveibeenpwned est injoignable. Elle
     * quittait la commande, et le ErrorListener de la console la journalisait
     * en `critical` avec son message — l'URL, qui porte le préfixe SHA-1 du
     * mot de passe. La commande échoue fermée, par un message qui ne le cite
     * pas.
     */
    public function testAnUnreachableBreachServiceFailsTheCommandWithoutLeakingTheHashPrefix(): void
    {
        $registrar = $this->createMock(CpgUserRegistrarInterface::class);
        $registrar->expects(self::never())->method('register');

        $validator = self::createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturnCallback(static function (mixed $value, mixed $constraints): ConstraintViolationList {
            if ($constraints instanceof NotCompromisedPassword) {
                throw new TransportException('Could not resolve host for "https://api.pwnedpasswords.com/range/5BAA6".');
            }

            return new ConstraintViolationList();
        });

        $tester = new CommandTester(new CreateCpgUserCommand($registrar, $validator));
        $tester->setInputs(['a-fresh-strong-password']);
        $exitCode = $tester->execute(['--username' => 'jane', '--password-stdin' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('haveibeenpwned', $tester->getDisplay());
        self::assertStringNotContainsString('5BAA6', $tester->getDisplay());
    }
}
