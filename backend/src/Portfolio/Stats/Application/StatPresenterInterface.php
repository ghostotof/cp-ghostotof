<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Application;

use App\Portfolio\Stats\Domain\Entity\Stat;

interface StatPresenterInterface
{
    /**
     * @return array{value: string, label: string, iconKey: string}
     */
    public function present(Stat $stat): array;
}
