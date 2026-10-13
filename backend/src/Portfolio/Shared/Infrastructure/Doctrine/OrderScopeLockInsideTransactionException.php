<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Infrastructure\Doctrine;

use LogicException;

/**
 * Le verrou d'un périmètre d'ordre a été demandé dans une transaction déjà
 * ouverte (issue #526) : un défaut de l'appelant, jamais une issue ordinaire.
 *
 * Imbriqué, le COMMIT du verrou ne serait qu'un `RELEASE SAVEPOINT` : le
 * trigger différé `trg_translation_group_single_position` (#390, #501)
 * refuserait au COMMIT de l'appelant, hors de portée du verrou, en
 * `DriverException` `23T90` ou `23T91` brute plutôt que sous le nom de
 * l'invariant. Le verrou, lui, ne
 * serait relâché qu'à ce COMMIT-là. D'où un refus avant toute écriture, sans
 * mapping HTTP : un 500 `critical` qui porte un nom. Le message ne cite que
 * la classe d'entité, jamais une donnée reçue.
 */
final class OrderScopeLockInsideTransactionException extends LogicException
{
    /**
     * @param class-string $scope
     */
    public static function forScope(string $scope): self
    {
        return self::named(\sprintf("du périmètre d'ordre %s", $scope));
    }

    /**
     * Les périmètres d'un `withLocks()` (issue #567), nommés ensemble.
     *
     * @param non-empty-list<class-string> $scopes
     */
    public static function forScopes(array $scopes): self
    {
        return self::named(\sprintf("des périmètres d'ordre %s", implode(', ', $scopes)));
    }

    private static function named(string $scopes): self
    {
        return new self(\sprintf("Le verrou %s ne se prend pas dans une transaction déjà ouverte : son COMMIT ne serait qu'un point de sauvegarde, et le refus du trigger des groupes de traduction échapperait à son nom.", $scopes));
    }
}
