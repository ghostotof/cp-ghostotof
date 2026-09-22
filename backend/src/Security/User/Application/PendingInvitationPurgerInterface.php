<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Exception\InvalidPurgeRetentionException;

/**
 * Cas d'usage "purger les invitations jamais activées" (issue #238, motif
 * RGPD) : un compte jamais activé (CpgUser::isAwaitingPasswordSetup() —
 * invité, pas activé, et dont le hachage de mot de passe est encore vide : un
 * mot de passe posé depuis le backoffice sort le compte de la purge) dont la
 * dernière invitation est antérieure à $maxAge est supprimé, jetons compris
 * (PasswordSetupToken, FK ON DELETE CASCADE). Un compte en attente portant
 * ROLE_SUPER n'est jamais purgé — décision humaine, comme la garde "dernier
 * super-admin" de la suppression manuelle (CpgUserAdministrator::delete).
 * Aucune notification à la personne : l'adresse purgée est précisément ce
 * qu'on supprime.
 */
interface PendingInvitationPurgerInterface
{
    /**
     * @param bool $dryRun si vrai, ne supprime ni ne journalise rien : le
     *                      résultat dit seulement ce qui *serait* purgé
     *
     * @throws InvalidPurgeRetentionException si $maxAge est inférieur à un jour
     *                                         (négatif, nul ou trop court : le
     *                                         seuil purgerait des invitations
     *                                         encore vivantes)
     */
    public function purge(\DateInterval $maxAge, bool $dryRun = false): PendingInvitationPurgeResult;
}
