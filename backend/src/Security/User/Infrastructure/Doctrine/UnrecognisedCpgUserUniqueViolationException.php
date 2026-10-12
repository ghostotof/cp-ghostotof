<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use RuntimeException;

/**
 * Une violation d'unicité sur `cpg_user` qui ne nomme aucun des index connus
 * de {@see \App\Security\User\Domain\Entity\CpgUser} (issue #364) : un index
 * renommé ou ajouté sans le code qui le lit. Un défaut du code, pas un
 * conflit du client — un 500 `critical`, plutôt qu'un 409 qui affirmerait une
 * cause non vérifiée.
 *
 * Le message ne cite que le nom de l'index (un identifiant SQL), jamais la
 * violation d'origine, dont le détail cite la valeur en conflit (issue #356).
 */
final class UnrecognisedCpgUserUniqueViolationException extends RuntimeException
{
    public static function onIndex(?string $index): self
    {
        return new self(null === $index
            ? 'Violation d\'unicité sur cpg_user, index non cité par la base.'
            : \sprintf('Violation d\'unicité sur cpg_user, index non reconnu : %s.', $index));
    }
}
