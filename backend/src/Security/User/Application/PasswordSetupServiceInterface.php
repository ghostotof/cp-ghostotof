<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Exception\UnusablePasswordSetupLinkException;
use SensitiveParameter;

/**
 * Parcours public "je définis mon mot de passe via le lien reçu par e-mail".
 * `validate()` sert au frontend à refuser un lien inutilisable avant la
 * saisie ; `complete()` consomme le jeton et active le compte. Un seul refus
 * pour tout lien qui ne peut pas servir, quel qu'en soit le motif (issue #362).
 */
interface PasswordSetupServiceInterface
{
    /**
     * @throws UnusablePasswordSetupLinkException si le lien est inconnu, expiré ou déjà utilisé
     */
    public function validate(#[SensitiveParameter] string $clearToken): void;

    /**
     * Hache le mot de passe, active le compte et marque le jeton comme utilisé.
     *
     * @throws UnusablePasswordSetupLinkException si le lien est inconnu, expiré ou déjà utilisé
     */
    public function complete(#[SensitiveParameter] string $clearToken, #[SensitiveParameter] string $plainPassword): void;
}
