<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Support;

use App\Ai\Assistant\Application\Corpus\CorpusRendererInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/** Renderer de test : rend un corpus fixe et retient la locale demandée. */
final class StubCorpusRenderer implements CorpusRendererInterface
{
    public ?Locale $lastLocale = null;

    public function __construct(private readonly string $corpus = "<documents>\n\nCORPUS\n\n</documents>\n")
    {
    }

    public function render(Locale $locale): string
    {
        $this->lastLocale = $locale;

        return $this->corpus;
    }
}
