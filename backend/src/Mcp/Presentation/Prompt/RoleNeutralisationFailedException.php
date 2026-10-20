<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Prompt;

use RuntimeException;

/**
 * Le nettoyage de l'argument `role` a échoué — PCRE ou la normalisation
 * Unicode ont rendu une erreur au lieu d'un texte. Un défaut du serveur, pas
 * une saisie invalide : le SDK le rend en `-32603` générique et le journalise
 * `error`, sans le message de la personne.
 *
 * Le message ne cite jamais le texte reçu, seulement la cause technique.
 */
final class RoleNeutralisationFailedException extends RuntimeException
{
    public static function fromPcre(): self
    {
        return new self(\sprintf('Nettoyage du rôle impossible : %s.', preg_last_error_msg()));
    }

    public static function fromNormalizer(): self
    {
        return new self('Nettoyage du rôle impossible : la normalisation NFKC a échoué.');
    }
}
