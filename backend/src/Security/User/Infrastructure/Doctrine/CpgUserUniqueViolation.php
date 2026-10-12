<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Doctrine;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;

/**
 * Nomme l'unicité de `cpg_user` qu'une violation a heurtée (issue #364) :
 * la table en porte deux, et dire « adresse déjà utilisée » pour un
 * identifiant pris envoyait l'administrateur chercher un compte qui n'existe
 * pas.
 *
 * Le nom de l'index est lu dans le message de PostgreSQL, **premier
 * identifiant entre guillemets** — `"…"` en anglais, `« … »` sous un
 * `lc_messages` français ; le nom lui-même n'est jamais traduit. Jamais une
 * simple recherche dans tout le message : le `DETAIL` qui suit cite la valeur
 * en conflit, et un identifiant `uniq_cpg_user_email` aurait fait prendre une
 * violation d'identifiant pour une adresse prise. Le message de DBAL ne
 * reprend pas la requête, rien de cité ne précède donc le nom.
 *
 * Aucune des exceptions rendues ne cite une valeur ni ne garde la violation
 * pour `previous` : son détail cite l'adresse ou l'identifiant, dont la partie
 * locale est souvent prénom.nom (issue #356).
 */
final class CpgUserUniqueViolation
{
    /** Un identifiant SQL entre guillemets droits ou chevrons, espaces insécables compris. */
    private const string QUOTED_IDENTIFIER = '/["«]\s*([A-Za-z0-9_]+)\s*["»]/u';

    public static function refusalFor(UniqueConstraintViolationException $violation): DomainException|UnrecognisedCpgUserUniqueViolationException
    {
        $index = 1 === preg_match(self::QUOTED_IDENTIFIER, $violation->getMessage(), $matches) ? $matches[1] : null;

        return match ($index) {
            CpgUser::EMAIL_UNIQUE_INDEX => EmailAlreadyUsedException::alreadyLinkedToAnAccount(),
            CpgUser::USERNAME_UNIQUE_INDEX => UsernameAlreadyUsedException::takenMeanwhile(),
            // Index renommé ou ajouté sans ce code : aucune cause affirmée.
            default => UnrecognisedCpgUserUniqueViolationException::onIndex($index),
        };
    }
}
