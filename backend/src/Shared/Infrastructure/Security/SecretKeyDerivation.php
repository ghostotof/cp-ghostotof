<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use SensitiveParameter;

/**
 * Une clé par usage d'`APP_SECRET` (issue #455).
 *
 * Le secret signait jusque-là le CSRF et les empreintes de login sous sa forme
 * brute, l'usage n'étant distingué que dans le message (un préfixe, ou rien) :
 * toute fonction future qui renverrait `HMAC(APP_SECRET, x)` pour un `x` choisi
 * par le client serait devenue un oracle de signature pour les autres. HKDF
 * (RFC 5869) tire du secret une clé de 32 octets par contexte : deux contextes,
 * deux clés sans relation calculable.
 *
 * Un contexte est une chaîne versionnée propre à l'usage
 * (`cp-ghostotof/<usage>/v1`) ; le changer change la clé, ce qui sert de
 * rotation ciblée. `ApplicationSecretUsesTest` exige que toute classe de
 * `src/` qui reçoit `kernel.secret` passe par ici, avec un contexte que nulle
 * autre n'emploie.
 */
final class SecretKeyDerivation
{
    /**
     * @return non-empty-string clé binaire de 32 octets
     *
     * @throws EmptyApplicationSecretException si le secret est vide
     */
    public static function derive(#[SensitiveParameter] string $secret, string $context): string
    {
        if ('' === $secret) {
            throw EmptyApplicationSecretException::forContext($context);
        }

        return hash_hkdf('sha256', $secret, 32, $context);
    }
}
