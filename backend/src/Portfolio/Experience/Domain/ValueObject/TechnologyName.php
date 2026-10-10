<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\ValueObject;

use App\Portfolio\Experience\Domain\Exception\InvalidTechnologyNameException;

/**
 * Nom d'une technologie du classement de la page Expériences : rogné, non
 * vide, au plus MAX_LENGTH caractères (issue #386).
 *
 * Rogné **avant** d'arriver au registrar et à l'administrator : le contrôle
 * d'unicité (`findOneByName()`) se fait sur cette valeur. Rogner dans
 * l'entité seulement aurait laissé passer `" PHP "` jusqu'à la contrainte
 * unique de la base, dont l'exception ferme l'EntityManager. Le type rend
 * l'oubli impossible : registrar, administrator et entité ne reçoivent
 * qu'un TechnologyName.
 *
 * `trim()` sans liste de caractères, comme le normaliseur `trim` du DTO
 * backoffice : les deux voies retirent exactement les mêmes blancs.
 */
final readonly class TechnologyName
{
    /** Longueur de la colonne `experience_technology.name`, que PostgreSQL compte en caractères. */
    public const int MAX_LENGTH = 180;

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidTechnologyNameException
     */
    public static function fromString(string $name): self
    {
        $trimmed = trim($name);

        if ('' === $trimmed) {
            throw InvalidTechnologyNameException::blank();
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidTechnologyNameException::tooLong(self::MAX_LENGTH);
        }

        return new self($trimmed);
    }
}
