<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\Command;

use App\Security\User\Domain\Entity\CpgUser;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Presentation\Command\CreateCpgUserCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Validator\Constraint;
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

        $validator = self::createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList([
            new ConstraintViolation('This password has been leaked in a data breach.', null, [], '', null, 'hunter2'),
        ]));

        $tester = new CommandTester(new CreateCpgUserCommand($registrar, $validator));

        $exitCode = $tester->execute(['--username' => 'jane', '--password' => 'hunter2-but-long-enough']);

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

        $exitCode = $tester->execute(['--username' => 'jane', '--password' => 'a-fresh-strong-password']);

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

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with(
                self::anything(),
                self::callback(static fn (Constraint $constraint): bool => $constraint instanceof NotCompromisedPassword),
            )
            ->willReturn(new ConstraintViolationList());

        $tester = new CommandTester(new CreateCpgUserCommand($registrar, $validator));
        $tester->execute(['--username' => 'jane', '--password' => 'a-fresh-strong-password']);
    }
}
