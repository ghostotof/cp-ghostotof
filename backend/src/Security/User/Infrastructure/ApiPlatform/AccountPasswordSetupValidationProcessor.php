<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Presentation\ApiResource\AccountPasswordSetupValidationResource;

/**
 * POST /api/account/password-setup/validate : délègue la validation du jeton
 * (lu dans le corps) à PasswordSetupService, qui lève 404 / 410 selon le cas.
 * Un retour sans exception = jeton exploitable = 204. Rien n'est écrit : c'est
 * un Processor parce que le jeton arrive dans un corps (audit A7, D6), pas
 * parce que l'opération modifie quoi que ce soit.
 *
 * La limitation de débit par IP est appliquée en amont par
 * PasswordSetupRateLimitRequestListener (kernel.request, décision D1) : elle ne
 * doit pas être répétée ici, sinon chaque appel consommerait deux jetons de
 * quota.
 *
 * @implements ProcessorInterface<AccountPasswordSetupValidationResource, null>
 */
final readonly class AccountPasswordSetupValidationProcessor implements ProcessorInterface
{
    public function __construct(
        private PasswordSetupServiceInterface $passwordSetupService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $this->passwordSetupService->validate($data->token);

        return null;
    }
}
