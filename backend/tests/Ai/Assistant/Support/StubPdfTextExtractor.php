<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Support;

use App\Ai\Assistant\Application\Corpus\PdfTextExtractorInterface;

/**
 * Extracteur de test : rend un texte préparé (null = fichier absent) et compte
 * ses appels, pour vérifier que le renderer relit le CV à chaque rendu.
 */
final class StubPdfTextExtractor implements PdfTextExtractorInterface
{
    public int $calls = 0;

    public function __construct(private readonly ?string $text)
    {
    }

    public function extract(): ?string
    {
        ++$this->calls;

        return $this->text;
    }
}
