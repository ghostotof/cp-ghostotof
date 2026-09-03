<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserInviteProcessor;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserProcessor;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserProvider;

/**
 * Listing + invitation + suppression des comptes CpgUser, réservé ROLE_SUPER
 * (cf. access_control ^/api/backoffice dans config/packages/security.yaml).
 *
 * La création directe (username + mot de passe) reste réservée à la commande
 * CLI app:user:create. Le POST ici *invite* : il prend une adresse e-mail,
 * dérive un identifiant, crée un compte en attente d'activation et envoie un
 * lien de définition de mot de passe (cf. BackofficeUserInviteInput /
 * App\Security\User\Application\CpgUserInviter).
 */
#[ApiResource(
    shortName: 'BackofficeUser',
    // Garde `email: null` dans la réponse (défaut API Platform : les valeurs
    // nulles sont omises) : le frontend attend toujours la clé pour distinguer
    // « compte sans e-mail » de « champ absent ».
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/backoffice/users',
            provider: BackofficeUserProvider::class,
        ),
        new Post(
            uriTemplate: '/backoffice/users',
            status: 201,
            input: BackofficeUserInviteInput::class,
            processor: BackofficeUserInviteProcessor::class,
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
        public ?string $email = null,
        /** @var 'pending'|'active' */
        public string $status = 'active',
    ) {
    }
}
