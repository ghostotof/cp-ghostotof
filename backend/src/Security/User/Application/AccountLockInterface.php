<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\CpgUserNotFoundException;
use Closure;

/**
 * Sérialise les écritures qui touchent à l'invitation d'un compte (issues
 * #435, #458) : l'émission d'un jeton par le handler d'invitation et
 * l'activation par le lien. Sans ce verrou commun, l'une pouvait passer entre
 * deux écritures de l'autre — deux jetons valables, ou un compte activé qui
 * en gardait un.
 *
 * L'opération s'exécute dans une transaction, compte verrouillé et relu ; elle
 * reçoit ce compte relu, à lire plutôt que tout état antérieur au verrou. Ordre imposé à toute
 * opération : le compte d'abord, ses jetons ensuite — l'ordre inverse
 * provoquerait un interblocage.
 */
interface AccountLockInterface
{
    /**
     * @template T
     *
     * @param Closure(CpgUser): T $operation reçoit le compte, relu sous le verrou
     *
     * @return T
     *
     * @throws CpgUserNotFoundException si le compte n'existe plus
     */
    public function whileLocked(CpgUser $user, Closure $operation): mixed;
}
