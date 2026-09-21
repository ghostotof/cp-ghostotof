<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Security\User\Infrastructure\ApiPlatform\AccountPasswordSetupValidationProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/account/password-setup/validate `{token}` — endpoint public
 * (aucune entrée dans access_control, exclu du contrôle CSRF cf.
 * CsrfCookieRequestSubscriber). Sert au frontend à savoir, avant d'afficher le
 * formulaire, si le lien reçu par e-mail est encore exploitable. Ne consomme
 * pas le jeton et n'écrit rien.
 *
 * Un POST pour une lecture, délibérément (audit A7, décision D6) : le jeton
 * est un secret, et un GET ne peut le porter que dans son URL — donc dans les
 * access logs du sidecar nginx et de l'ingress. Remplace l'ancien
 * `GET /api/account/password-setup/{token}`, à ne pas réintroduire.
 *
 * 204 sans corps (`output: false`) : le statut EST la réponse. Jeton absent ou
 * vide -> 422, inconnu -> 404, expiré / déjà utilisé -> 410, quota IP dépassé
 * -> 429 (avant toute validation, cf. PasswordSetupRateLimitRequestListener).
 */
#[ApiResource(
    shortName: 'AccountPasswordSetupValidation',
    operations: [
        new Post(
            uriTemplate: '/account/password-setup/validate',
            status: 204,
            read: false,
            output: false,
            processor: AccountPasswordSetupValidationProcessor::class,
        ),
    ],
)]
final class AccountPasswordSetupValidationResource
{
    // Mêmes bornes que AccountPasswordSetupResource::$token.
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $token = '';
}
