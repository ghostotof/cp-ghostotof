<?php

declare(strict_types=1);

namespace App\Ai\Translation\Domain\ValueObject;

/**
 * Le résultat d'une traduction : le même dictionnaire que la requête, dans la
 * locale cible. Construit uniquement après validation de la réponse du modèle
 * (chaque clé demandée présente, chaîne non vide) — cf. le traducteur.
 */
final readonly class TranslatedFields
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public array $fields,
    ) {
    }
}
