<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Security\User\Application\PasswordSetupRateLimiterInterface;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Presentation\ApiResource\AccountPasswordSetupStatusResource;
use App\Shared\Infrastructure\ApiPlatform\ResolvesUriVariables;
use Symfony\Component\HttpFoundation\Request;

/**
 * GET /api/account/password-setup/{token} : borne d'abord le débit par IP,
 * puis délègue la validation du jeton à PasswordSetupService (qui lève 404 /
 * 410 selon le cas). Un retour = jeton exploitable.
 *
 * @implements ProviderInterface<AccountPasswordSetupStatusResource>
 */
final readonly class AccountPasswordSetupProvider implements ProviderInterface
{
    use ResolvesUriVariables;

    public function __construct(
        private PasswordSetupServiceInterface $passwordSetupService,
        private PasswordSetupRateLimiterInterface $rateLimiter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AccountPasswordSetupStatusResource
    {
        $request = $context['request'] ?? null;
        \assert($request instanceof Request);
        $this->rateLimiter->consume($request->getClientIp() ?? 'unknown');

        $this->passwordSetupService->validate($this->uriVariableString($uriVariables, 'token'));

        return new AccountPasswordSetupStatusResource(valid: true);
    }
}
