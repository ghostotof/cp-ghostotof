<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Exception;

use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Exception métier levée lorsqu'on tente de charger/modifier les textes de
 * section À propos d'une locale qui n'a pas encore été seedée.
 */
final class AboutSettingsNotFoundException extends \DomainException
{
    public static function forLocale(Locale $locale): self
    {
        return new self(sprintf('Aucun réglage "À propos" trouvé pour la locale "%s".', $locale->value));
    }
}
