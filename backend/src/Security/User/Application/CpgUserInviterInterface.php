<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;

/**
 * Cas d'usage "inviter un utilisateur depuis le backoffice" : crée un compte
 * en attente d'activation à partir d'une adresse e-mail, génère un jeton de
 * définition de mot de passe et déclenche l'envoi de l'e-mail d'invitation.
 * Distinct de CpgUserRegistrar (création CLI, mot de passe fourni d'emblée).
 */
interface CpgUserInviterInterface
{
    /**
     * @param Locale $locale langue de l'e-mail d'invitation et du lien envoyé
     *
     * @throws EmailAlreadyUsedException si l'adresse est déjà rattachée à un compte
     */
    public function invite(string $email, Locale $locale): CpgUser;
}
