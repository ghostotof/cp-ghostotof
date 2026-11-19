<?php

declare(strict_types=1);

namespace App\Security\Authentication\Application;

/**
 * D'où vient une écriture que le journal de sécurité enregistre, quand le
 * même cas d'usage peut être déclenché de deux manières (#652) : par une
 * requête HTTP, dont l'auteur se lit dans le jeton de sécurité, ou à la main
 * en ligne de commande, hors de toute requête, où il n'y a pas de jeton et où
 * `anonymous` suggérerait à tort un appelant HTTP.
 *
 * Un énuméré plutôt qu'un booléen : l'appelant écrit `AuditOrigin::Console`,
 * jamais un `true` dont on ne sait pas ce qu'il affirme.
 */
enum AuditOrigin
{
    /** Une requête HTTP : l'auteur est celui du jeton de sécurité courant. */
    case Request;

    /** Une commande lancée à la main : l'auteur est `console`, une personne que le journal ne sait pas nommer. */
    case Console;
}
