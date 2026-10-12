<?php

declare(strict_types=1);

namespace App\Contact\Domain\Exception;

use App\Shared\Domain\Exception\MessageQueueUnavailableException;
use Throwable;

/**
 * Le message de contact n'a pas pu être mis en file (issue #485) : 503. Le
 * quota du visiteur reste consommé, avant l'envoi et à dessein (relecture de
 * #485, `.claude/rules/deploiement.md`) : le consommer après laissait passer
 * une rafale concurrente.
 */
final class ContactMessageQueueUnavailableException extends MessageQueueUnavailableException
{
    /**
     * @param Throwable $previous la panne du transport, gardée pour le journal,
     *                            jamais citée dans le message (le `detail`)
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct('Votre message n\'a pas pu être pris en compte : le service est momentanément indisponible, réessayez dans quelques instants.', 0, $previous);
    }
}
