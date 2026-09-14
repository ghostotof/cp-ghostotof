<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain;

/**
 * Spec 0004 D5 — ce qu'un contenu doit savoir faire pour être ordonné par
 * `OrderAssigner` : dire sous quelle clé il se range, et accepter une position.
 *
 * Toute la subtilité tient dans `orderingKey()`. Pour un contenu localisé,
 * c'est le **groupe de traduction**, pas l'id : les lignes FR et EN d'un même
 * contenu répondent donc la même clé et reçoivent la même position, ce qui est
 * exactement l'exigence « un déplacement suit le contenu quelle que soit la
 * langue ». Pour `WatchedProduct`, qui n'a pas de locale, c'est l'id.
 *
 * L'interface vit dans le domaine partagé et ne connaît ni Doctrine ni API
 * Platform : neuf ressources partagent ainsi une seule logique d'ordre plutôt
 * que d'en recopier neuf variantes.
 */
interface Orderable
{
    /**
     * Clé de regroupement pour l'ordre, en RFC 4122 — deux entités qui
     * répondent la même clé sont le même contenu et se déplacent ensemble.
     */
    public function orderingKey(): string;

    public function moveToPosition(int $position): void;
}
