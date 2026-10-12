<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use LogicException;
use Throwable;

/**
 * Les membres d'un groupe de traduction partagent le périmètre d'ordre de
 * leur table : la catégorie, pour les cartes « moi », qui s'ordonnent par
 * catégorie (issue #501). Un groupe rangé dans deux catégories recevrait deux
 * rangs, un par catégorie, et ses langues ne seraient plus présentées au même
 * endroit — le défaut que la spec 0004 D3 interdit pour la position.
 *
 * L'application ne produit pas un tel groupe (une carte n'a pas de setter de
 * catégorie, une version se crée dans la catégorie de son groupe) : seule une
 * écriture qui la contourne, SQL ou migration, le peut, et la base la refuse
 * au COMMIT (SQLSTATE `23T91`, même trigger différé que la position). Comme
 * {@see TranslationGroupHasSeveralPositionsException}, c'est un défaut du
 * pipeline, jamais une saisie : 500 `critical`, ni
 * `ProblemExceptionInterface` ni entrée `exception_to_status`, et
 * {@see LogicException} plutôt que {@see \DomainException}.
 */
final class TranslationGroupAcrossSeveralScopesException extends LogicException
{
    /**
     * La base a refusé au COMMIT une transaction qui laissait un groupe dans
     * plusieurs périmètres. Son message, repris tel quel, nomme la table, le
     * groupe et ses périmètres ; il ne contient rien que le client ait saisi.
     */
    public static function refusedAtCommit(Throwable $databaseRefusal): self
    {
        return new self(\sprintf('La base a refusé la transaction : %s', $databaseRefusal->getMessage()), 0, $databaseRefusal);
    }
}
