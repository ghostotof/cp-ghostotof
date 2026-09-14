<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

use App\Portfolio\Shared\Domain\Exception\IncompleteOrderException;
use App\Portfolio\Shared\Domain\Exception\UnknownOrderEntryException;
use App\Portfolio\Watch\Domain\Entity\WatchedProduct;
use App\Portfolio\Watch\Domain\Exception\InvalidWatchedProductException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductSlugAlreadyUsedException;
use App\Portfolio\Watch\Domain\Exception\WatchedProductSlugIsImmutableException;
use App\Portfolio\Watch\Domain\ValueObject\VersionSource;
use Symfony\Component\Uid\Uuid;

interface WatchedProductAdministratorInterface
{
    /**
     * @throws WatchedProductSlugAlreadyUsedException si le produit est déjà suivi
     * @throws InvalidWatchedProductException         si la version contredit sa source
     */
    public function create(
        string $slug,
        string $label,
        VersionSource $versionSource,
        ?string $version,
        int $position,
    ): WatchedProduct;

    /**
     * @throws WatchedProductNotFoundException        si l'id est inconnu
     * @throws WatchedProductSlugIsImmutableException si le slug soumis diffère du slug stocké
     * @throws InvalidWatchedProductException         si la version contredit sa source
     */
    public function update(
        Uuid $id,
        string $slug,
        string $label,
        VersionSource $versionSource,
        ?string $version,
        int $position,
    ): WatchedProduct;

    /**
     * @throws WatchedProductNotFoundException si l'id est inconnu
     */
    public function delete(Uuid $id): void;

    /**
     * Spec 0004 D4/D5 : réordonne tout le catalogue. `WatchedProduct` n'a pas
     * de groupe de traduction (D6 : une version n'est pas une traduction) —
     * `$keys` est donc l'ensemble des **ids** existants, en RFC 4122, une
     * occurrence chacun.
     *
     * @param list<string> $keys ids, en RFC 4122
     *
     * @throws UnknownOrderEntryException une clé n'est pas dans le catalogue, ou y apparaît plus
     *                                    d'une fois
     * @throws IncompleteOrderException   un id du catalogue est absent de `$keys`
     */
    public function reorder(array $keys): void;
}
