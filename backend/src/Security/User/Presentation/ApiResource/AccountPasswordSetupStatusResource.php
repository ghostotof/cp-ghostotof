<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Security\User\Infrastructure\ApiPlatform\AccountPasswordSetupProvider;

/**
 * GET /api/account/password-setup/{token} — endpoint public (aucune entrée
 * dans access_control, exclu du contrôle CSRF cf. CsrfCookieRequestSubscriber).
 * Sert au frontend à savoir, avant d'afficher le formulaire, si le lien reçu
 * par e-mail est encore exploitable. Jeton inconnu -> 404, expiré / déjà
 * utilisé -> 410, quota IP dépassé -> 429.
 */
#[ApiResource(
    shortName: 'AccountPasswordSetupStatus',
    operations: [
        new Get(
            uriTemplate: '/account/password-setup/{token}',
            provider: AccountPasswordSetupProvider::class,
        ),
    ],
)]
final class AccountPasswordSetupStatusResource
{
    public function __construct(
        public bool $valid = false,
    ) {
    }
}
