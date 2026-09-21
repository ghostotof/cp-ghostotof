<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Presentation\ApiResource\AccountPasswordSetupResource;

/**
 * POST /api/account/password-setup : délègue à PasswordSetupService::complete
 * (hache le mot de passe, active le compte, consomme le jeton). Le jeton est
 * lu dans le corps, jamais dans le chemin (audit A7, D6). Réponse 204, aucun
 * corps.
 *
 * La limitation de débit par IP est appliquée en amont par
 * PasswordSetupRateLimitRequestListener (kernel.request, décision D1) : ne pas
 * la répéter ici (double consommation du quota).
 *
 * @implements ProcessorInterface<AccountPasswordSetupResource, null>
 */
final readonly class AccountPasswordSetupProcessor implements ProcessorInterface
{
    public function __construct(
        private PasswordSetupServiceInterface $passwordSetupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->passwordSetupService->complete($data->token, $data->password);

        return null;
    }
}
