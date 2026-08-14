<?php

declare(strict_types=1);

namespace App\Portfolio\Stats\Application;

use App\Portfolio\Stats\Domain\Entity\Stat;

final class StatPresenter implements StatPresenterInterface
{
    public function present(Stat $stat): array
    {
        return [
            'value' => $stat->getValue(),
            'label' => $stat->getLabel(),
            'iconKey' => $stat->getIconKey(),
        ];
    }
}
