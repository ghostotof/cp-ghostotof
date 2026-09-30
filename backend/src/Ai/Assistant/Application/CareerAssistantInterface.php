<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application;

use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

interface CareerAssistantInterface
{
    /**
     * Répond au dernier message de la conversation, fragment par fragment, à
     * partir du corpus de la locale (spec 0005 D8, D9).
     *
     * L'appel au fournisseur est lancé avant le retour : un échec avant le
     * premier fragment lève AssistantUnavailableException ici même, tant que le
     * statut HTTP peut encore changer. Un échec pendant le flux la lève depuis
     * le générateur. Sa valeur de retour, lue après consommation, porte les
     * jetons et la durée.
     *
     * @return \Generator<int, string, mixed, AnswerUsage>
     *
     * @throws AssistantUnavailableException
     * @throws AssistantRateLimitExceededException quota du compte atteint (D6),
     *                                             levée avant tout appel au fournisseur
     */
    public function answer(Conversation $conversation, Locale $locale): \Generator;
}
