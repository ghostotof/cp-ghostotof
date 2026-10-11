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
 * Les blancs retirés sont ceux d'Unicode (revue de #386) : `trim()` ne
 * connaît que l'ASCII, et une espace insécable ou un caractère de largeur
 * nulle collés depuis une page web refaisaient le doublon. Le DTO backoffice
 * valide par cette même méthode (Assert\Callback) : une seule règle.
 *
 * Refusés : l'UTF-8 invalide (l'argv de la CLI peut porter n'importe quels
 * octets) et tout caractère de contrôle — PostgreSQL refuse l'octet NUL dans
 * un `varchar`, et l'exception DBAL sortait en 500 `critical`.
 */
final readonly class TechnologyName
{
    /** Longueur de la colonne `experience_technology.name`, que PostgreSQL compte en caractères. */
    public const int MAX_LENGTH = 180;

    /**
     * Séparateurs Unicode (`\p{Z}`), blancs (`\s`), et les invisibles de
     * format qu'un copier-coller apporte : largeur nulle (U+200B à U+200D),
     * gluon de mots (U+2060), indicateur d'ordre des octets (U+FEFF).
     */
    private const string SURROUNDING_BLANKS = '/^[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+|[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+\z/u';

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidTechnologyNameException
     */
    public static function fromString(string $name): self
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            throw InvalidTechnologyNameException::notUtf8();
        }

        // Ne peut échouer : l'encodage vient d'être vérifié.
        $trimmed = (string) preg_replace(self::SURROUNDING_BLANKS, '', $name);

        if ('' === $trimmed) {
            throw InvalidTechnologyNameException::blank();
        }

        if (1 === preg_match('/\p{Cc}/u', $trimmed)) {
            throw InvalidTechnologyNameException::controlCharacter();
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidTechnologyNameException::tooLong(self::MAX_LENGTH);
        }

        return new self($trimmed);
    }
}
