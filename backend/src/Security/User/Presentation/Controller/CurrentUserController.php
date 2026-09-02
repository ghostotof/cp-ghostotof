<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Controller;

use App\Security\User\Application\CpgUserPresenterInterface;
use App\Security\User\Domain\Entity\CpgUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Protégé par l'access_control "^/api/me" (config/packages/security.yaml) :
 * n'est jamais atteint sans un JWT valide. Utilisé par le frontend pour savoir,
 * après un rechargement de page, si le cookie httpOnly encore valide correspond
 * à un utilisateur connecté (impossible de le lire directement en JS).
 */
final readonly class CurrentUserController
{
    public function __construct(
        private Security $security,
        private CpgUserPresenterInterface $cpgUserPresenter,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        /** @var CpgUser $user */
        $user = $this->security->getUser();

        return new JsonResponse(['user' => $this->cpgUserPresenter->present($user)]);
    }
}
