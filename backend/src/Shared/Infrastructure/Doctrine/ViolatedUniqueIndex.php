<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Nomme l'index unique qu'une violation a heurté : la seule lecture du message
 * de PostgreSQL, partagée par les classes qui distinguent deux unicités
 * (`CpgUserUniqueViolation`, `McpClientRegistrar`, issues #364 et #652).
 *
 * Le nom est le **premier identifiant entre guillemets** du message — `"…"` en
 * anglais, `« … »` sous un `lc_messages` français, espaces insécables comprises ;
 * le nom lui-même n'est jamais traduit. Jamais une recherche dans tout le
 * message : le `DETAIL` qui suit cite la valeur en conflit, et une valeur qui
 * ressemblerait au nom d'un autre index ferait conclure à la mauvaise unicité.
 * Un identifiant hors `[A-Za-z0-9_]` n'est pas reconnu : `null`, aucune cause
 * n'est affirmée.
 */
final class ViolatedUniqueIndex
{
    /** Un identifiant SQL entre guillemets droits ou chevrons, espaces insécables compris. */
    private const string QUOTED_IDENTIFIER = '/["«]\s*([A-Za-z0-9_]+)\s*["»]/u';

    /**
     * @return string|null le nom de l'index violé, `null` si le message n'en cite aucun lisible
     */
    public static function of(UniqueConstraintViolationException $exception): ?string
    {
        return 1 === preg_match(self::QUOTED_IDENTIFIER, $exception->getMessage(), $matches) ? $matches[1] : null;
    }
}
