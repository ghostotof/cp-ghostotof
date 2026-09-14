<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Application;

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
}
