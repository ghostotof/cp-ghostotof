<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Presentation\ApiResource\AccountPasswordSetupStatusResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * GET /api/account/password-setup/{token} : délègue la validation du jeton à
 * PasswordSetupService (qui lève 404 / 410 selon le cas). Un retour = jeton
 * exploitable.
 *
 * La limitation de débit par IP est appliquée en amont par
 * PasswordSetupRateLimitRequestListener (kernel.request, décision D1) : elle ne
 * doit pas être répétée ici, sinon chaque appel consommerait deux jetons de
 * quota.
 *
 * @implements ProviderInterface<AccountPasswordSetupStatusResource>
 */
final readonly class AccountPasswordSetupProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private PasswordSetupServiceInterface $passwordSetupService,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AccountPasswordSetupStatusResource
    {
        $this->passwordSetupService->validate($this->uriVariableString($uriVariables, 'token'));

        return new AccountPasswordSetupStatusResource(valid: true);
    }
}
