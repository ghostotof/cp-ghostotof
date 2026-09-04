<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Presentation\ApiResource\AccountPasswordSetupResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;

/**
 * POST /api/account/password-setup/{token} : délègue à
 * PasswordSetupService::complete (hache le mot de passe, active le compte,
 * consomme le jeton). Réponse 204, aucun corps.
 *
 * La limitation de débit par IP est appliquée en amont par
 * PasswordSetupRateLimitRequestListener (kernel.request, décision D1) : ne pas
 * la répéter ici (double consommation du quota).
 *
 * @implements ProcessorInterface<AccountPasswordSetupResource, null>
 */
final readonly class AccountPasswordSetupProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private PasswordSetupServiceInterface $passwordSetupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->passwordSetupService->complete($this->uriVariableString($uriVariables, 'token'), $data->password);

        return null;
    }
}
