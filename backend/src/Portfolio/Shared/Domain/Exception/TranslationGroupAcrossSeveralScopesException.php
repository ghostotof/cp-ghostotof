<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use LogicException;
use Symfony\Component\Uid\Uuid;
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
 *
 * Deux origines, comme pour la position : l'application la lève **avant
 * toute écriture** quand une opération du backoffice toucherait un groupe déjà
 * réparti (`forGroup()`, issue #561 — un tel groupe peut rester d'avant la
 * garde de #501), la base au COMMIT pour ce qui contourne l'application
 * (`refusedAtCommit()`).
 */
final class TranslationGroupAcrossSeveralScopesException extends LogicException
{
    /**
     * L'application a trouvé, avant d'écrire, un groupe déjà réparti sur
     * plusieurs périmètres (issue #561). Le message donne le groupe et ses
     * périmètres, ce qu'il faut retrouver en base, et la correction ; il ne
     * contient rien que le client ait saisi — le groupe est relu en base.
     *
     * @param list<string> $scopes les périmètres où vivent ses membres
     */
    public static function forGroup(Uuid $translationGroup, array $scopes): self
    {
        return new self(\sprintf(
            'Le groupe de traduction %s est réparti sur plusieurs périmètres (%s) : supprimer l\'entrée égarée puis recréer sa version dans le bon périmètre, ou la détacher.',
            $translationGroup->toRfc4122(),
            implode(', ', $scopes),
        ));
    }

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
