<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use DomainException;

/**
 * Le lien de définition de mot de passe ne peut pas servir, quel qu'en soit
 * le motif : jeton inconnu, remplacé par une relance, emporté par la purge ou
 * la suppression du compte, expiré, déjà utilisé, ou compte déjà actif.
 *
 * Un seul refus pour tous ces cas, et un message constant (issue #362) : le
 * statut, le `type` et le `detail` de la réponse en découlent, et un appelant
 * anonyme ne doit rien apprendre du devenir d'un lien. Distinguer « inconnu »
 * (404) de « expiré ou déjà utilisé » (410) disait, passé le délai de purge,
 * qu'un compte avait été activé — un jeton consommé étant conservé. Les
 * constructeurs nommés ne servent qu'à la lecture du code : ce qui distingue
 * les cas pour l'exploitation, c'est le journal de sécurité
 * (`password-setup-token-rejected` / `-replayed`), jamais la réponse.
 *
 * Ne jamais rendre le message variable, ni ajouter une seconde exception à
 * statut propre pour l'un de ces cas : ce serait rouvrir la fuite.
 */
final class UnusablePasswordSetupLinkException extends DomainException
{
    private const string MESSAGE = 'Ce lien de définition de mot de passe n\'est pas ou plus valide.';

    /**
     * Aucun jeton ne correspond : jamais émis, remplacé par une relance, ou
     * supprimé avec son compte (purge, suppression depuis le backoffice).
     */
    public static function unknown(): self
    {
        return new self(self::MESSAGE);
    }

    /**
     * Le jeton existe mais a expiré, a déjà servi, ou vise un compte déjà
     * actif.
     */
    public static function expiredOrAlreadyUsed(): self
    {
        return new self(self::MESSAGE);
    }
}
