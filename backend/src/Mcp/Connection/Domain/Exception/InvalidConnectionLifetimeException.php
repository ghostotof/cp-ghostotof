<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use InvalidArgumentException;

/**
 * Une durée de la connexion MCP lue en configuration (#649, paramètres
 * `app.mcp.lifetime.*` de `config/services.yaml`) n'est pas admissible :
 * illisible, nulle, ou en contradiction avec une autre.
 *
 * Défaut de configuration, jamais une erreur client : levée à la construction
 * du service {@see \App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes},
 * aucune entrée `framework.exceptions`, un 500 `critical` au premier usage.
 *
 * Le message nomme le paramètre (`codeLifetime`, `accessLifetime`…), jamais
 * la valeur reçue ; l'exception native de PHP sur une durée illisible n'est
 * pas chaînée, son message citant la valeur.
 */
final class InvalidConnectionLifetimeException extends InvalidArgumentException
{
    public static function unreadable(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions n\'est pas un intervalle ISO 8601 lisible.', $parameter));
    }

    public static function notPositive(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions doit être strictement positive.', $parameter));
    }

    /**
     * Le délai d'inactivité ou la durée maximale d'une connexion sous un jour
     * (relecture du lot B de #649) : la purge présélectionne par « maintenant
     * moins la durée, plus un jour », et une durée plus courte mettrait cette
     * borne dans le futur.
     */
    public static function belowOneDay(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions doit être d\'au moins un jour, la marge de présélection de la purge.', $parameter));
    }

    /**
     * Le délai d'inactivité ou la durée maximale d'une connexion ne dépasse
     * pas un jour (la marge de présélection de la purge) plus la durée d'un
     * code plus une heure, celle d'un changement d'heure, plus une minute de
     * jeu (troncature à la seconde, horloges) (#726, CR1) : la
     * connexion d'un code encore échangeable serait candidate de la purge,
     * dont le marquage croiserait le remplacement de l'échange en
     * interblocage.
     */
    public static function notBeyondFreshConnectionWindow(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions doit dépasser un jour, la marge de présélection de la purge, plus la durée « codeLifetime » d\'un code, une heure de changement d\'heure et une minute de jeu : une connexion dont le code est encore échangeable serait candidate de la purge.', $parameter));
    }

    /**
     * La durée d'une connexion jamais échangée (#730) compte un jour, un mois
     * ou une année : des unités de calendrier, qui durent 23, 24 ou 25 heures
     * selon le jour. Elle est du temps écoulé seulement (heures, minutes,
     * secondes), pour que la borne de présélection de la purge, `maintenant
     * − durée + une minute`, soit exacte à la seconde.
     */
    public static function notElapsedTime(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions doit être du temps écoulé (heures, minutes, secondes), sans jour, mois ni année.', $parameter));
    }

    /**
     * La durée d'une connexion jamais échangée (#730) ne dépasse pas la durée
     * d'un code plus deux fois la minute de jeu (#726, CR1) : la présélection
     * de la purge recule d'une minute, et la connexion d'un code encore
     * échangeable (au plus sa durée plus cette minute) pourrait devenir
     * candidate — le marquage croiserait le remplacement de l'échange.
     */
    public static function notBeyondCodeWindow(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions doit dépasser la durée « codeLifetime » d\'un code de deux minutes de jeu : une connexion dont le code est encore échangeable serait candidate de la purge.', $parameter));
    }

    /**
     * La durée d'une connexion jamais échangée (#730) atteint le délai
     * d'inactivité : elle n'écourterait plus rien, l'échéance des connexions
     * jamais échangées n'aurait plus de raison d'être.
     */
    public static function notBelowIdle(string $parameter): self
    {
        return new self(\sprintf('La durée « %s » des connexions doit être inférieure au délai d\'inactivité « idleLifetime ».', $parameter));
    }

    public static function idleExceedsMaximum(): self
    {
        return new self('Le délai d\'inactivité « idleLifetime » des connexions ne peut dépasser leur durée maximale « maximumLifetime ».');
    }

    public static function accessTokenOutlivesRefreshToken(): self
    {
        return new self('La durée « accessLifetime » ne peut dépasser celle du jeton de rafraîchissement « refreshLifetime ».');
    }
}
