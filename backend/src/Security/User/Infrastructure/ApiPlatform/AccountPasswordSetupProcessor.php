<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Security\User\Application\PasswordSetupRateLimiterInterface;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Presentation\ApiResource\AccountPasswordSetupResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /api/account/password-setup/{token} : borne d'abord le débit par IP,
 * puis délègue à PasswordSetupService::complete (hache le mot de passe, active
 * le compte, consomme le jeton). Réponse 204, aucun corps.
 *
 * @implements ProcessorInterface<AccountPasswordSetupResource, null>
 */
final readonly class AccountPasswordSetupProcessor implements ProcessorInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private PasswordSetupServiceInterface $passwordSetupService,
        private PasswordSetupRateLimiterInterface $rateLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);
        $this->rateLimiter->consume($request->getClientIp() ?? 'unknown');

        $this->passwordSetupService->complete($this->uriVariableString($uriVariables, 'token'), $data->password);

        return null;
    }
}
