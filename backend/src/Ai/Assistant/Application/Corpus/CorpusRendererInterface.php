<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Application\Corpus;

use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Rend, pour une locale, le corpus que l'assistant a le droit de connaître :
 * exactement ce que le palier nominatif lit déjà (spec 0005 D5, ADR 0004 D7).
 * Assemblé à chaque appel, jamais précalculé ni mis en cache.
 */
interface CorpusRendererInterface
{
    public function render(Locale $locale): string;
}
