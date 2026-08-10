<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Exception;

/**
 * Exception métier levée lorsqu'on tente d'enregistrer une technologie déjà
 * présente dans le classement (même nom).
 */
final class ExperienceTechnologyAlreadyExistsException extends \DomainException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Une technologie existe déjà avec le nom "%s".', $name));
    }
}
