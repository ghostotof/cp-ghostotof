<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Exception;

use App\Shared\Domain\Exception\MessageQueueUnavailableException;
use Throwable;

/**
 * L'invitation n'a pas pu être mise en file (issue #485) : 503. Une première
 * invitation retire le compte qu'elle venait de créer (CpgUserInviter), une
 * relance garde sa génération avancée.
 */
final class InvitationQueueUnavailableException extends MessageQueueUnavailableException
{
    /**
     * @param Throwable $previous la panne du transport, gardée pour le journal,
     *                            jamais citée dans le message (le `detail`)
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct('L\'invitation n\'a pas pu être envoyée : le service est momentanément indisponible, réessayez dans quelques instants.', 0, $previous);
    }
}
