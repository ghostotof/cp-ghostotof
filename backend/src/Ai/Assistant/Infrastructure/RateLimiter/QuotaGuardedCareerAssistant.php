<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\RateLimiter;

use App\Ai\Assistant\Application\AssistantRateLimiterInterface;
use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Consomme le quota `career_assistant` du compte authentifié (spec 0005 D6)
 * avant de laisser l'assistant réel contacter le fournisseur.
 *
 * Un décorateur plutôt qu'une ligne dans le contrôleur (spec §7 : aucune
 * logique de quota dedans) ou dans SymfonyAiCareerAssistant (seule classe à
 * importer Symfony AI, qui n'a pas à connaître le compte). L'ordre voulu
 * tient à la construction : la conversation arrive ici déjà validée, en VO —
 * une requête refusée (422) n'atteint jamais ce point et ne coûte rien.
 */
#[AsDecorator(decorates: CareerAssistantInterface::class)]
final readonly class QuotaGuardedCareerAssistant implements CareerAssistantInterface
{
    public function __construct(
        #[AutowireDecorated]
        private CareerAssistantInterface $inner,
        private AssistantRateLimiterInterface $rateLimiter,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function answer(Conversation $conversation, Locale $locale): \Generator
    {
        $account = $this->tokenStorage->getToken()?->getUserIdentifier();
        if (null === $account || '' === $account) {
            // L'access_control réserve la route à ROLE_TRUSTED : arriver ici
            // sans compte est un défaut de câblage, pas un appel à laisser passer.
            throw new \LogicException("L'assistant exige un compte authentifié.");
        }

        $this->rateLimiter->consume($account);

        return $this->inner->answer($conversation, $locale);
    }
}
