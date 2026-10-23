<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use Defuse\Crypto\Encoding;
use Defuse\Crypto\Key;
use SensitiveParameter;
use SensitiveParameterValue;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * La clé qui chiffre les codes d'autorisation et les jetons de
 * rafraîchissement que league remet aux clients (#647) : dérivée
 * d'`APP_SECRET` sous son propre contexte (#455), jamais le secret brut.
 * Remise à league comme {@see Key} defuse : une chaîne y serait lue comme un
 * mot de passe (`Crypto::encryptWithPassword`, un PBKDF2 à chaque chiffrement)
 * et le secret brut servirait de clé à un usage de plus.
 *
 * Changer le contexte, ou `APP_SECRET`, rend illisibles tous les codes en vol
 * (10 minutes) et tous les rafraîchissements remis (30 jours) : chaque client
 * MCP doit se reconnecter. Une rotation se décide et s'annonce.
 *
 * Le secret est gardé dans un {@see SensitiveParameterValue} (aucun dump ne
 * l'affiche, #493) et la clé dérivée à chaque key(), jamais à la
 * construction : le `cache:clear` du `docker build` n'a pas d'`APP_SECRET`
 * (#474).
 */
final readonly class OAuthEncryptionKey
{
    /** Contexte de la clé dérivée d'`APP_SECRET` (issue #455). */
    private const string KEY_CONTEXT = 'cp-ghostotof/oauth-encryption/v1';

    /** @var SensitiveParameterValue<string> */
    private SensitiveParameterValue $secret;

    public function __construct(
        #[SensitiveParameter]
        #[Autowire('%kernel.secret%')]
        string $secret,
    ) {
        $this->secret = new SensitiveParameterValue($secret);
    }

    /**
     * @throws EmptyApplicationSecretException si `kernel.secret` est vide
     */
    public function key(): Key
    {
        return self::fromSecret($this->secret->getValue());
    }

    /**
     * Les 32 octets d'HKDF-SHA256 sous KEY_CONTEXT, mis sous la forme
     * versionnée et sommée que defuse relit (`KEY_CURRENT_VERSION`) : la
     * longueur d'une clé defuse, sans aléa propre — la même clé sur chaque
     * pod, à chaque démarrage.
     *
     * @throws EmptyApplicationSecretException si le secret est vide
     */
    public static function fromSecret(#[SensitiveParameter] string $secret): Key
    {
        $bytes = SecretKeyDerivation::derive($secret, self::KEY_CONTEXT);

        return Key::loadFromAsciiSafeString(Encoding::saveBytesToChecksummedAsciiSafeString(Key::KEY_CURRENT_VERSION, $bytes));
    }
}
