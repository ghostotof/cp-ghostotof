<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Exception\InvitationQueueUnavailableException;

/**
 * Cas d'usage "inviter un utilisateur depuis le backoffice" : crée un compte
 * en attente d'activation à partir d'une adresse e-mail et publie la demande
 * d'envoi de l'e-mail d'invitation (le jeton de définition de mot de passe est
 * créé côté handler, cf. SendAccountInvitationHandler / audit C2).
 * Distinct de CpgUserRegistrar (création CLI, mot de passe fourni d'emblée).
 */
interface CpgUserInviterInterface
{
    /**
     * @param Locale $locale langue de l'e-mail d'invitation et du lien envoyé
     *
     * @throws EmailAlreadyUsedException           si l'adresse est déjà rattachée à un compte
     * @throws InvitationQueueUnavailableException si la file n'a pas pris l'invitation ;
     *                                             le compte créé est alors retiré (issue #485)
     */
    public function invite(string $email, Locale $locale): CpgUser;

    /**
     * Renvoie l'invitation : régénère le jeton (invalidant le précédent) et
     * redispatch l'e-mail. Réservé aux comptes encore en attente d'activation.
     *
     * @throws AccountNotAwaitingActivationException si le compte est déjà activé
     *                                               ou n'a jamais été invité
     * @throws InvitationQueueUnavailableException   si la file n'a pas pris l'invitation
     *                                               (issue #485) ; la génération reste avancée
     */
    public function reinvite(CpgUser $user, Locale $locale): void;
}
