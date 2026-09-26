<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\ValueObject;

/**
 * Ce que coûte une réponse, lu après la fin du flux. Les jetons sont null si
 * le fournisseur ne les a pas transmis.
 */
final readonly class AnswerUsage
{
    public function __construct(
        public ?int $promptTokens,
        public ?int $completionTokens,
        public int $durationMs,
    ) {
    }
}
