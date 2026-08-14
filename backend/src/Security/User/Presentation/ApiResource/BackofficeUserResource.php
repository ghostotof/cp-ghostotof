<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserProcessor;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserProvider;

/**
 * Listing + suppression des comptes CpgUser, réservé ROLE_SUPER (cf.
 * access_control ^/api/backoffice dans config/packages/security.yaml).
 * Aucune opération de création : les comptes se créent exclusivement via la
 * commande CLI app:user:create (cf. CreateCpgUserCommand), pas de formulaire
 * d'inscription même côté backoffice.
 */
#[ApiResource(
    shortName: 'BackofficeUser',
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/users',
            provider: BackofficeUserProvider::class,
        ),
        new Delete(
            uriTemplate: '/backoffice/users/{id}',
            provider: BackofficeUserProvider::class,
            processor: BackofficeUserProcessor::class,
        ),
    ],
)]
final readonly class BackofficeUserResource
{
    public function __construct(
        public int $id,
        public string $username,
        /** @var list<string> */
        public array $roles,
    ) {
    }
}
