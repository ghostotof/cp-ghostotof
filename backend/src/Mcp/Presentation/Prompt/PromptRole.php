<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Prompt;

use Mcp\Exception\PromptGetException;

/**
 * L'argument `role` du prompt `present_for_role`, tel qu'il entre dans le
 * message : le texte libre d'une personne qui recrute, donc une donnée, jamais
 * une instruction (spec 0007, « Prompt »).
 *
 * Deux garde-fous, parce qu'un prompt construit côté serveur ne doit pas
 * devenir un relais d'injection :
 *
 *  - **une borne**, {@see self::MAX_CHARACTERS} caractères (pas octets), au-delà
 *    de laquelle le texte est refusé — jamais tronqué : couper une phrase en
 *    change le sens, et la personne ne le verrait pas ;
 *  - **un bloc qu'il ne peut pas refermer** : le message l'encadre entre
 *    `<role>` et `</role>`, et les chevrons du texte sont retirés, si bien
 *    qu'aucune balise qu'il porte ne ferme le bloc avant l'heure. Le corpus de
 *    l'assistant de parcours neutralise ses `<documents>` de la même façon.
 *    Les caractères de contrôle sont retirés aussi, sauf le saut de ligne et la
 *    tabulation, qu'une description de poste peut porter.
 *
 * Une valeur refusée est une `PromptGetException`, la seule erreur que le SDK
 * 0.8.1 rend avec son message (`-32603`, quand le protocole attendrait
 * `-32602` : le SDK ne laisse pas le choix). Son message ne cite jamais le
 * texte reçu : le SDK le journalise avec l'erreur.
 */
final readonly class PromptRole
{
    /** Un intitulé de poste et quelques exigences ; une fiche de poste entière est refusée (décision du 2026-10-19). */
    public const int MAX_CHARACTERS = 500;

    private function __construct(
        public string $text,
    ) {
    }

    /**
     * @throws PromptGetException texte vide (avant ou après nettoyage) ou au-delà de la borne
     */
    public static function fromInput(string $input): self
    {
        $trimmed = trim($input);

        // La borne se compte sur ce que la personne a saisi, avant tout
        // nettoyage : retirer des caractères ne doit pas faire passer un texte
        // trop long.
        if (mb_strlen($trimmed) > self::MAX_CHARACTERS) {
            throw new PromptGetException(\sprintf('The role is too long: describe it in %d characters at most.', self::MAX_CHARACTERS));
        }

        // Chevrons et caractères de contrôle (hors \n et \t) retirés ; une
        // entrée en UTF-8 invalide n'arrive pas jusqu'ici, le décodage JSON de
        // la requête l'a déjà refusée.
        $neutralised = str_replace(['<', '>'], '', $trimmed);
        $neutralised = trim((string) preg_replace('/[^\P{Cc}\n\t]/u', '', $neutralised));

        // Vide après nettoyage aussi : un rôle fait de seules balises ne
        // laisse rien à présenter.
        if ('' === $neutralised) {
            throw new PromptGetException('The role is empty: describe the role to present the author for.');
        }

        return new self($neutralised);
    }
}
