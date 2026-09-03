<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserInvitationProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/backoffice/users/{id}/invitation — renvoi de l'invitation à définir
 * son mot de passe, réservé ROLE_SUPER. Régénère le jeton et redispatch
 * l'e-mail. Réponse 202 sans corps (`output: false`) : l'envoi est asynchrone.
 * `read: false` : le processor résout lui-même l'id (404 si inconnu).
 */
#[ApiResource(
    shortName: 'BackofficeUserInvitation',
    operations: [
        new Post(
            uriTemplate: '/backoffice/users/{id}/invitation',
            status: 202,
            read: false,
            output: false,
            processor: BackofficeUserInvitationProcessor::class,
        ),
    ],
)]
final readonly class BackofficeUserInvitationResource
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['fr', 'en'])]
        public string $locale = '',
    ) {
    }
}
