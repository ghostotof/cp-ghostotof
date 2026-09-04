<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

/**
 * Exception métier levée par Locale::fromString() quand une valeur venue de
 * l'extérieur (segment d'URL `{locale}`, argument de commande…) ne correspond à
 * aucune langue gérée.
 *
 * Point d'audit I3 : elle remplace le `\ValueError` brut que levait
 * `Locale::from()`. `api_platform.yaml` mappait auparavant `ValueError: 404` —
 * un fourre-tout qui transformait en « 404 Not Found » *n'importe quelle*
 * ValueError du projet, y compris un vrai bug sans rapport avec la locale, en le
 * masquant au passage. Le mapping porte désormais sur cette exception précise.
 */
final class InvalidLocaleException extends \DomainException
{
    public static function forValue(string $value): self
    {
        return new self(sprintf('Langue inconnue : "%s".', $value));
    }
}
