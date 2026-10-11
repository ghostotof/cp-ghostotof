<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use SensitiveParameter;

/**
 * HMAC-SHA256 sous une clé dérivée d'`APP_SECRET` pour un seul usage (issue
 * #487) : la dérivation ({@see SecretKeyDerivation}), l'algorithme et la
 * comparaison en temps constant, écrits une fois pour tous les usages du
 * secret.
 *
 * Objet de valeur plutôt que service : chaque usage a sa clé, son contexte et
 * son encodage, fixés à la création, et rien d'autre ne varie. La dérivation
 * a lieu dans {@see self::fromSecret()}, qui refuse un secret vide : l'usage
 * qui veut ce refus au premier calcul plutôt qu'à sa propre construction crée
 * l'objet à ce moment-là (le cache de #474, le cookie CSRF, l'empreinte de
 * login).
 *
 * Un contexte donné produit exactement le MAC que l'usage calculait à la main
 * avant #487 : passer par cette classe n'est pas une rotation.
 */
final readonly class KeyedMac
{
    private const string ALGORITHM = 'sha256';

    private function __construct(
        #[SensitiveParameter]
        private string $key,
        private MacEncoding $encoding,
    ) {
    }

    /**
     * @param string $context contexte versionné de l'usage
     *                        (`cp-ghostotof/<usage>/v<n>`), son `KEY_CONTEXT`
     *
     * @throws EmptyApplicationSecretException si le secret est vide
     */
    public static function fromSecret(#[SensitiveParameter] string $secret, string $context, MacEncoding $encoding): self
    {
        return new self(SecretKeyDerivation::derive($secret, $context), $encoding);
    }

    /**
     * @return non-empty-string le MAC de `$data`, dans l'encodage de l'usage
     */
    public function sign(string $data): string
    {
        return hash_hmac(self::ALGORITHM, $data, $this->key, MacEncoding::Binary === $this->encoding);
    }

    /**
     * Vrai si `$mac` est le MAC de `$data` sous cette clé et dans cet
     * encodage, comparé en temps constant. Un MAC vide n'est jamais valable :
     * le MAC calculé ne l'est jamais.
     */
    public function verifies(string $mac, string $data): bool
    {
        return hash_equals($this->sign($data), $mac);
    }
}
