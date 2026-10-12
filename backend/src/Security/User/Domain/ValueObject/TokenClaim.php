<?php

declare(strict_types=1);

namespace App\Security\User\Domain\ValueObject;

/**
 * L'issue d'une réclamation de jeton de définition de mot de passe (issues
 * #356, #447, #458). Les trois échecs ne se valent pas pour le journal : seul
 * un jeton déjà consommé qui revient est un rejeu ; un jeton expiré n'est pas
 * un événement, un jeton disparu (remplacé par une relance, compte supprimé)
 * est un lien inconnu.
 */
enum TokenClaim
{
    case Claimed;
    case AlreadyUsed;
    case Expired;
    case Gone;
}
