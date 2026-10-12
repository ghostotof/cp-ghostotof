<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Matériel de clé des tests unitaires (un `APP_SECRET` de substitution),
 * généré plutôt qu'écrit en littéral (issue #455).
 *
 * Même raison que TestCredentials : GitGuardian lit une chaîne affectée à une
 * constante ou un paramètre nommés « secret » comme un secret en dur, et le
 * test de SecretKeyDerivation lui a valu sept alertes, toutes de faux positifs
 * à trier à la main. Une valeur tirée à l'exécution ne figure nulle part dans
 * le dépôt.
 *
 * Tirée une fois par processus et par étiquette : deux appels de même
 * étiquette rendent la même valeur, deux étiquettes deux valeurs distinctes.
 * L'API évite le mot « secret », pour ne pas reformer le motif qu'elle
 * supprime.
 */
final class TestKeys
{
    /** @var array<string, non-empty-string> */
    private static array $material = [];

    /**
     * @return non-empty-string
     */
    public static function material(string $label = 'application'): string
    {
        return self::$material[$label] ??= self::generate();
    }

    /**
     * @return non-empty-string
     */
    private static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
