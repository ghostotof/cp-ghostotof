<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Prompt;

use App\Shared\Domain\Text\TextNeutralisationFailedException;
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

    /** Le neutraliseur commun a échoué (#667) : sa cause technique est l'exception chaînée, jamais le texte. */
    public static function fromNeutralisation(TextNeutralisationFailedException $failure): self
    {
        return new self('Nettoyage du rôle impossible : la neutralisation commune a échoué.', $failure->getCode(), $failure);
    }
}
