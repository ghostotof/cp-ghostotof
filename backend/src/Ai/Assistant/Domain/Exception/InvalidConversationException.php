<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Domain\Exception;

/**
 * La conversation reçue ne respecte pas ses invariants. Mappée 422
 * (api_platform.yaml). Le message ne cite jamais le contenu reçu.
 */
final class InvalidConversationException extends \DomainException
{
}
