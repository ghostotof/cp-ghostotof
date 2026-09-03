<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Exception\InvalidPasswordSetupTokenException;
use App\Security\User\Domain\Exception\PasswordSetupTokenExpiredException;

/**
 * Parcours public "je définis mon mot de passe via le lien reçu par e-mail".
 * `validate()` sert au frontend à afficher « lien invalide / expiré » avant la
 * saisie ; `complete()` consomme le jeton et active le compte.
 */
interface PasswordSetupServiceInterface
{
    /**
     * @throws InvalidPasswordSetupTokenException si le jeton est inconnu
     * @throws PasswordSetupTokenExpiredException si le jeton est expiré ou déjà utilisé
     */
    public function validate(string $clearToken): void;

    /**
     * Hache le mot de passe, active le compte et marque le jeton comme utilisé.
     *
     * @throws InvalidPasswordSetupTokenException si le jeton est inconnu
     * @throws PasswordSetupTokenExpiredException si le jeton est expiré ou déjà utilisé
     */
    public function complete(string $clearToken, string $plainPassword): void;
}
