<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Portfolio\Stats\Domain\Entity\Stat;
use App\Portfolio\Stats\Domain\Exception\StatNotFoundException;

interface StatAdministratorInterface
{
    public function create(Locale $locale, string $value, string $label, string $iconKey, int $position): Stat;

    /**
     * @throws StatNotFoundException si l'id est inconnu
     */
    public function update(int $id, string $value, string $label, string $iconKey, int $position): Stat;

    /**
     * @throws StatNotFoundException si l'id est inconnu
     */
    public function delete(int $id): void;
}
