<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Service;

use App\Portfolio\Watch\Domain\Exception\ReleaseCycleProductNotFoundException;
use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\ValueObject\ProductReleaseCycles;

/**
 * D'où viennent les cycles de vie des versions.
 *
 * L'abstraction existe pour que la couche Application ignore jusqu'au nom du
 * fournisseur : elle demande les cycles d'un produit et reçoit des Value
 * Objects, jamais du JSON. C'est ce qui permet de tester tout ce qui est en
 * amont sans réseau, et de changer de source sans toucher au domaine.
 */
interface ReleaseCycleSourceInterface
{
    /**
     * @throws ReleaseCycleProductNotFoundException    si le slug est inconnu de la source
     * @throws ReleaseCycleSourceUnavailableException si la source est injoignable ou illisible
     */
    public function fetchProduct(string $slug): ProductReleaseCycles;
}
