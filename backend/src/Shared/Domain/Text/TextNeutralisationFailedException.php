<?php

declare(strict_types=1);

namespace App\Shared\Domain\Text;

use RuntimeException;

/**
 * La neutralisation d'un texte a échoué — PCRE ou la normalisation Unicode ont
 * rendu une erreur au lieu d'un texte (UTF-8 invalide, limite épuisée). Un
 * défaut du serveur, jamais un texte vide : chaque utilisateur du
 * {@see TagWordNeutraliser} la reprend sous sa propre exception nommée.
 *
 * Le message ne cite jamais le texte reçu, seulement la cause technique.
 */
final class TextNeutralisationFailedException extends RuntimeException
{
    public static function fromPcre(): self
    {
        return new self(\sprintf('Neutralisation du texte impossible : %s.', preg_last_error_msg()));
    }

    public static function fromNormalizer(): self
    {
        return new self('Neutralisation du texte impossible : la normalisation NFKC a échoué.');
    }
}
