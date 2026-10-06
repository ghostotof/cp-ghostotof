<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\ValueObject;

use App\Portfolio\Experience\Domain\Exception\InvalidExperienceYearsException;

/**
 * Temps cumulé passé sur une technologie, en années : un nombre fini compris
 * entre 0 et MAX, bornes incluses (issue #372).
 *
 * Seul endroit où la règle est écrite. L'entité ne reçoit qu'une durée déjà
 * valide, le DTO backoffice et la commande CLI délèguent ici leur validation,
 * et la contrainte `chk_experience_technology_years` la reproduit en base
 * (Version20261006120000) pour ce qu'aucun de ces chemins ne voit : une
 * écriture SQL, ou une ligne fautive persistée avant la correction.
 */
final readonly class ExperienceYears
{
    /** Une technologie tout juste abordée : zéro est une durée légitime. */
    public const float MIN = 0.0;

    /**
     * Assez large pour ne jamais gêner une vraie saisie, assez serrée pour
     * refuser l'absurde qu'une page publique afficherait tel quel.
     */
    public const float MAX = 100.0;

    private function __construct(public float $value)
    {
    }

    /**
     * @throws InvalidExperienceYearsException
     */
    public static function fromFloat(float $years): self
    {
        // `is_finite()` d'abord : NaN rend fausse toute comparaison, il
        // passerait donc entre `< MIN` et `> MAX` sans elle.
        if (!is_finite($years) || $years < self::MIN || $years > self::MAX) {
            throw InvalidExperienceYearsException::outOfRange($years, self::MIN, self::MAX);
        }

        // -0.0 passe la borne basse (il vaut 0) mais `json_encode` le publie
        // « -0 » : l'addition IEEE 754 le ramène au zéro ordinaire.
        return new self($years + 0.0);
    }

    /**
     * Entrée textuelle (commande CLI). `is_numeric('1e999')` est vrai et
     * `(float)` le rend en INF : la conversion ne dispense pas des bornes.
     *
     * @throws InvalidExperienceYearsException
     */
    public static function fromString(string $years): self
    {
        if (!is_numeric($years)) {
            throw InvalidExperienceYearsException::notANumber();
        }

        return self::fromFloat((float) $years);
    }
}
