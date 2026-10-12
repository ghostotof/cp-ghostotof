<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserProcessor;
use App\Security\User\Presentation\ApiResource\BackofficeUserResource;
use App\Shared\Infrastructure\ApiPlatform\UnexpectedActingUserException;
use App\Tests\Support\FixedSecurity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Supprimer un compte se fait au nom d'un CpgUser : c'est lui qu'on protège
 * de sa propre suppression. L'access_control (^/api/backoffice) en garantit
 * un ; atteindre ce Processor sans lui est un défaut de câblage (issue #338),
 * refusé avant toute suppression, sous un nom qui dit ce qui manquait.
 */
final class BackofficeUserProcessorTest extends TestCase
{
    #[DataProvider('actingUsersThatAreNotACpgUser')]
    public function testNothingIsDeletedWithoutACpgUserActingAccount(?UserInterface $actingUser, string $received): void
    {
        $administrator = $this->createMock(CpgUserAdministratorInterface::class);
        $administrator->expects(self::never())->method(self::anything());
        $processor = new BackofficeUserProcessor($administrator, FixedSecurity::holding($actingUser));

        $this->expectException(UnexpectedActingUserException::class);
        $this->expectExceptionMessage($received);

        $processor->process(
            new BackofficeUserResource(id: '019968a0-0000-7000-8000-000000000001', username: 'compte-test', roles: ['ROLE_USER']),
            new Delete(),
            ['id' => '019968a0-0000-7000-8000-000000000001'],
        );
    }

    /**
     * @return iterable<string, array{?UserInterface, string}>
     */
    public static function actingUsersThatAreNotACpgUser(): iterable
    {
        yield 'aucun compte' => [null, 'reçu : aucun compte'];
        // Un compte authentifié d'un autre type (le palier de base, un futur
        // fournisseur) : le message ne doit pas prétendre qu'il n'y en a pas.
        yield 'compte d\'un autre type' => [new InMemoryUser('invite', null, ['ROLE_USER']), 'reçu : '.InMemoryUser::class];
    }
}
