<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\RateLimiter;

use App\Ai\Assistant\Application\AssistantRateLimiterInterface;
use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
 *
 * Un appel lancé coûte une unité même si le fournisseur échoue ensuite (503)
 * ou si le corpus ne se compose pas (500) : c'est voulu, une panne ne doit
 * pas devenir un moyen de rejouer sans compter. Le prix est qu'une panne
 * prolongée peut épuiser le quota d'un compte sans qu'il obtienne de réponse.
 *
 * Un refus est tracé sur `ai_usage` (niveau fixe, voir monolog.yaml) avec le
 * compte, rien du contenu (D10) : le 429 que le noyau journalise en `info` sur
 * le canal applicatif ne sortirait jamais d'un pod de production, et un
 * compte qui boucle sur son plafond passerait inaperçu. Le logger est injecté
 * par son identifiant et non par #[WithMonologChannel] : sur un décorateur,
 * le passage de décoration réécrit les tags et le canal était perdu (le refus
 * partait sur le canal applicatif, vérifié par AnswerControllerTest).
 */
#[AsDecorator(decorates: CareerAssistantInterface::class)]
final readonly class QuotaGuardedCareerAssistant implements CareerAssistantInterface
{
    public function __construct(
        #[AutowireDecorated]
        private CareerAssistantInterface $inner,
        private AssistantRateLimiterInterface $rateLimiter,
        private TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'monolog.logger.ai_usage')]
        private LoggerInterface $logger,
    ) {
    }

    // Jamais de `yield` ici : answer() deviendrait un générateur, le quota ne
    // serait consommé qu'au premier fragment lu — après l'envoi du 200, donc
    // sans 429 possible, et après le lancement de l'appel facturé.
    public function answer(Conversation $conversation, Locale $locale): \Generator
    {
        $account = $this->tokenStorage->getToken()?->getUserIdentifier();
        if (null === $account || '' === $account) {
            // L'access_control réserve la route à ROLE_TRUSTED : arriver ici
            // sans compte est un défaut de câblage, pas un appel à laisser passer.
            throw new UnauthenticatedAssistantCallException();
        }

        try {
            $this->rateLimiter->consume($account);
        } catch (AssistantRateLimitExceededException $exception) {
            $this->logger->info('Assistant de parcours : quota atteint.', ['outcome' => 'rate-limited', 'account' => $account]);

            throw $exception;
        }

        return $this->inner->answer($conversation, $locale);
    }
}
