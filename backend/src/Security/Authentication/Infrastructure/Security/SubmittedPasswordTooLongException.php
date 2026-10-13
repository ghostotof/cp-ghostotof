<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\BadCredentialsException;

/**
 * Un mot de passe soumis au login dépasse `CpgUser::MAX_PASSWORD_LENGTH`
 * octets, la borne du hasher (issue #588) : aucun compte ne peut l'avoir, et
 * {@see OverlongPasswordRefusalListener} le refuse avant tout chargement de
 * compte.
 *
 * Une BadCredentialsException, et rien de plus, pour deux raisons :
 *
 *  - **la réponse reste le 401 commun, octet pour octet** (audit A10) : le
 *    failure handler de Lexik lit `getMessageKey()`, hérité (« Invalid
 *    credentials. »), et tire le statut du code, 0 donc 401. Ne surcharger ni
 *    l'un ni l'autre ;
 *  - **une classe à elle** pour que {@see FailedLoginTimingEqualizer} et le
 *    journal d'audit la reconnaissent *avant* d'appeler
 *    `Passport::getUser()`, qui chargerait le compte (une requête SQL, et la
 *    compensation de l'identifiant inconnu seulement : l'écart reviendrait).
 *
 * Le message est constant et ne cite rien : la ligne `Authenticator failed.`
 * du canal `security` journalise l'exception.
 */
final class SubmittedPasswordTooLongException extends BadCredentialsException
{
    public function __construct()
    {
        parent::__construct('The presented password exceeds the maximum length.');
    }
}
