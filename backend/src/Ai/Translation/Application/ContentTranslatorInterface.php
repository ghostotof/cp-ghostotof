<?php

declare(strict_types=1);

namespace App\Ai\Translation\Application;

use App\Ai\Translation\Domain\Exception\TranslationUnavailableException;
use App\Ai\Translation\Domain\ValueObject\TranslatedFields;
use App\Ai\Translation\Domain\ValueObject\TranslationRequest;

/**
 * Frontière du contexte (ADR 0004, D1) : l'application et la présentation ne
 * connaissent que ce contrat, jamais le bundle ni le fournisseur. Implémentation
 * unique : Infrastructure\SymfonyAi\SymfonyAiContentTranslator.
 */
interface ContentTranslatorInterface
{
    /**
     * @throws TranslationUnavailableException si le fournisseur échoue ou répond hors du schéma demandé
     */
    public function translate(TranslationRequest $request): TranslatedFields;
}
