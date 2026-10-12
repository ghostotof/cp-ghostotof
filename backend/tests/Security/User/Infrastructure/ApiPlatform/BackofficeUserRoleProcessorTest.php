<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Put;
use App\Security\User\Application\CpgUserRoleAdministratorInterface;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserRoleProcessor;
use App\Security\User\Presentation\ApiResource\BackofficeUserRoleResource;
use App\Shared\Infrastructure\ApiPlatform\UnexpectedActingUserException;
use App\Tests\Support\FixedSecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Changer un rôle se fait au nom d'un CpgUser : c'est lui qu'on empêche de se
 * rétrograder. Sans lui, c'est un défaut de câblage (issue #338), refusé avant
 * tout changement de rôle, sous un nom qui dit ce qui manquait.
 */
final class BackofficeUserRoleProcessorTest extends TestCase
{
    #[DataProvider('actingUsersThatAreNotACpgUser')]
    public function testNoRoleChangesWithoutACpgUserActingAccount(?UserInterface $actingUser, string $received): void
    {
        $administrator = $this->createMock(CpgUserRoleAdministratorInterface::class);
        $administrator->expects(self::never())->method(self::anything());
        $processor = new BackofficeUserRoleProcessor($administrator, FixedSecurity::holding($actingUser));

        $this->expectException(UnexpectedActingUserException::class);
        $this->expectExceptionMessage($received);

        $processor->process(new BackofficeUserRoleResource(superAdmin: true), new Put(), ['id' => '019968a0-0000-7000-8000-000000000001']);
    }

    /**
     * @return iterable<string, array{?UserInterface, string}>
     */
    public static function actingUsersThatAreNotACpgUser(): iterable
    {
        yield 'aucun compte' => [null, 'reçu : aucun compte'];
        yield 'compte d\'un autre type' => [new InMemoryUser('invite', null, ['ROLE_USER']), 'reçu : '.InMemoryUser::class];
    }
}
