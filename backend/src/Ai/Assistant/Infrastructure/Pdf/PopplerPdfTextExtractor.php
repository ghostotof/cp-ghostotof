<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Pdf;

use App\Ai\Assistant\Application\Corpus\PdfTextExtractorInterface;
use Spatie\PdfToText\Exceptions\BinaryNotFoundException;
use Spatie\PdfToText\Exceptions\PdfNotFound;
use Spatie\PdfToText\Pdf;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;

/**
 * Extrait le CV nominatif avec pdftotext (poppler-utils, installé dans l'étape
 * `base` de l'image), repli prévu par la spec 0005 sur smalot/pdfparser : sur
 * le vrai CV, ce dernier ne rendait aucune ligne vide entre paragraphes, des
 * centaines d'artefacts `<>` et des puces détachées (journal de la spec).
 *
 * Rien n'est gardé entre deux appels (D7) : ni cache, ni propriété mutable.
 * Rien n'est journalisé non plus (D10) — cette classe n'a pas de logger, et
 * c'est voulu.
 *
 * Le processus est borné par un délai : la mesure sur le vrai CV donne une
 * douzaine de millisecondes, un PDF qui en demanderait des centaines de fois
 * plus est un défaut, pas un CV.
 */
final readonly class PopplerPdfTextExtractor implements PdfTextExtractorInterface
{
    private const int TIMEOUT_SECONDS = 5;

    public function __construct(
        #[Autowire(param: 'app.cv_file_path')]
        private string $cvFilePath,
        private ExtractedTextNormalizer $normalizer,
    ) {
    }

    public function extract(): ?string
    {
        if (!is_file($this->cvFilePath)) {
            return null;
        }

        try {
            $text = new Pdf()
                ->setOptions(['enc UTF-8'])
                ->setTimeout(self::TIMEOUT_SECONDS)
                ->setPdf($this->cvFilePath)
                ->text();
        } catch (BinaryNotFoundException|PdfNotFound|ProcessException) {
            // Jamais chaînée : ProcessFailedException recopie la sortie
            // standard du processus, c'est-à-dire le texte du CV.
            throw new CvTextExtractionException('Extraction du texte du CV impossible.');
        }

        return $this->normalizer->normalize($text);
    }
}
