<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ai\Translation\Infrastructure\ApiPlatform\BackofficeTranslationProcessor;
use App\Ai\Translation\Presentation\ApiResource\BackofficeTranslationResource;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserProcessor;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserRoleProcessor;
use App\Security\User\Presentation\ApiResource\BackofficeUserResource;
use App\Security\User\Presentation\ApiResource\BackofficeUserRoleResource;
use App\Shared\Infrastructure\ApiPlatform\UnauthenticatedProcessorCallException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Les Processors qui agissent au nom du compte connecté s'en remettent à
 * l'access_control (^/api/backoffice) pour qu'il y en ait un. S'ils sont
 * atteints sans compte, c'est un défaut de câblage, jamais un appel à laisser
 * passer anonymement (issue #338) : ils le disent sous un nom qui se
 * reconnaît dans les journaux, avant tout effet (quota, suppression, rôle).
 *
 * Le noyau est démarré sans requête ni jeton : `Security::getUser()` rend
 * null, exactement le cas que la garde couvre.
 */
final class UnauthenticatedProcessorCallTest extends KernelTestCase
{
    /**
     * @param class-string<ProcessorInterface<object, object|null>> $processor
     */
    #[DataProvider('processorsActingOnBehalfOfTheConnectedAccount')]
    public function testACallWithoutAnAuthenticatedAccountIsRefusedUnderItsOwnName(string $processor, object $data, Operation $operation): void
    {
        $service = self::getContainer()->get($processor);
        self::assertInstanceOf(ProcessorInterface::class, $service);

        try {
            $service->process($data, $operation, ['id' => '019968a0-0000-7000-8000-000000000001']);
            self::fail('Un appel sans compte authentifié ne doit jamais être traité.');
        } catch (UnauthenticatedProcessorCallException $exception) {
            // Le message nomme le Processor : la garde est partagée par trois contextes.
            self::assertStringContainsString($processor, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{class-string, object, Operation}>
     */
    public static function processorsActingOnBehalfOfTheConnectedAccount(): iterable
    {
        yield 'suppression d\'un compte' => [
            BackofficeUserProcessor::class,
            new BackofficeUserResource(id: '019968a0-0000-7000-8000-000000000001', username: 'compte-test', roles: ['ROLE_USER']),
            new Delete(),
        ];
        yield 'changement de rôle' => [BackofficeUserRoleProcessor::class, new BackofficeUserRoleResource(superAdmin: true), new Put()];
        yield 'traduction assistée' => [
            BackofficeTranslationProcessor::class,
            new BackofficeTranslationResource(sourceLocale: 'fr', targetLocale: 'en', fields: ['title' => 'Bonjour']),
            new Post(),
        ];
    }
}
