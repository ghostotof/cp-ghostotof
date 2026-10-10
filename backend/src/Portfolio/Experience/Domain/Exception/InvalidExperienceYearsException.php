<?php

declare(strict_types=1);

namespace App\Portfolio\Experience\Domain\Exception;

use DomainException;

/**
 * Exception métier levée quand le temps cumulé d'une technologie n'est pas un
 * nombre de l'intervalle publiable (issue #372) : illisible, négatif, au-delà
 * de la borne haute, ou non fini (INF, NaN) — ce dernier cas ne s'encode pas
 * en JSON et mettait la route publique GET /api/experience/technologies en 500.
 *
 * **Volontairement absente d'`exception_to_status` et de `framework.exceptions`.**
 * Le DTO backoffice valide par ExperienceYears avant tout Processor : depuis
 * l'API, cette exception n'est atteignable que si un chemin d'écriture
 * contourne cette validation. Ce serait un défaut serveur, qui doit sortir en
 * 500 `critical` et non se fondre dans les 422 d'un client. Seule la commande
 * CLI la rattrape, pour en afficher le message.
 */
final class InvalidExperienceYearsException extends DomainException
{
    public static function outOfRange(float $years, float $minYears, float $maxYears): self
    {
        return new self(\sprintf(
            'Le temps cumulé doit être un nombre compris entre %s et %s ans (reçu : %s).',
            $minYears,
            $maxYears,
            // var_export plutôt que %s : la valeur exacte (%s arrondit à 14
            // chiffres) et « NAN » sans le warning de PHP 8.5 sur sa conversion.
            var_export($years, true),
        ));
    }

    public static function notANumber(): self
    {
        return new self('Le temps cumulé doit être un nombre (ex. 13.5).');
    }
}
