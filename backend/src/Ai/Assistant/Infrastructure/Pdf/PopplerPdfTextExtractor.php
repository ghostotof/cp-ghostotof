<?php

declare(strict_types=1);

namespace App\Ai\Assistant\Infrastructure\Pdf;

use App\Ai\Assistant\Application\Corpus\PdfTextExtractorInterface;
use Psr\Log\LoggerInterface;
use Spatie\PdfToText\Exceptions\PdfNotFound;
use Spatie\PdfToText\Pdf;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Extrait le CV nominatif avec pdftotext (poppler-utils, installé dans l'étape
 * `base` de l'image), repli prévu par la spec 0005 sur smalot/pdfparser : sur
 * le vrai CV, ce dernier ne rendait aucune ligne vide entre paragraphes, des
 * centaines d'artefacts `<>` et des puces détachées (journal de la spec).
 *
 * Rien n'est gardé entre deux appels (D7) : ni cache, ni propriété mutable.
 *
 * **Mode dégradé** (revue de branche, décision du propriétaire) : un CV
 * présent mais inutilisable — binaire absent, PDF illisible, délai dépassé,
 * aucun texte, texte au-delà de la borne — rend `null`, et la section se dit
 * indisponible ; l'assistant répond quand même. La panne se voit dans un
 * `warning` (visible en production, LOG_LEVEL=warning) qui nomme une raison
 * stable, un code de sortie ou une longueur, **jamais** le texte, la sortie
 * standard ni la sortie d'erreur du processus (D10) : ProcessFailedException
 * recopie les deux dans son message, elle n'est donc ni journalisée ni
 * relancée.
 *
 * Le processus est borné par un délai (la mesure sur le vrai CV donne une
 * douzaine de millisecondes) et lancé **sans l'environnement du worker** :
 * Symfony Process lui transmettrait sinon DATABASE_URL, APP_SECRET et les clés
 * d'API, à portée d'une faille du parseur.
 */
final readonly class PopplerPdfTextExtractor implements PdfTextExtractorInterface
{
    public const string DEFAULT_BINARY = '/usr/bin/pdftotext';

    /**
     * Le texte entre en entier dans chaque appel facturé : borné, le coût
     * l'est par construction (ADR 0004). Le vrai CV en compte ~6 400.
     */
    public const int MAX_CHARACTERS = 30_000;

    private const int TIMEOUT_SECONDS = 5;

    private const string WARNING = 'Extraction du CV nominatif impossible : section déclarée indisponible.';

    public function __construct(
        #[Autowire(param: 'app.cv_file_path')]
        private string $cvFilePath,
        private ExtractedTextNormalizer $normalizer,
        private LoggerInterface $logger,
        private string $binary = self::DEFAULT_BINARY,
    ) {
    }

    public function extract(): ?string
    {
        if (!is_file($this->cvFilePath)) {
            return null;
        }
        if (!is_executable($this->binary)) {
            return $this->unavailable('binary-missing');
        }

        try {
            $text = $this->normalizer->normalize($this->runPdfToText());
        } catch (ProcessTimedOutException) {
            return $this->unavailable('timeout');
        } catch (ProcessFailedException $exception) {
            return $this->unavailable('extraction-failed', ['exitCode' => $exception->getProcess()->getExitCode()]);
        } catch (PdfNotFound) {
            return $this->unavailable('file-unreadable');
        } catch (CvTextExtractionException) {
            return $this->unavailable('normalization-failed');
        }

        $characters = mb_strlen($text);
        if (0 === $characters) {
            return $this->unavailable('no-text');
        }
        if ($characters > self::MAX_CHARACTERS) {
            return $this->unavailable('too-long', ['characters' => $characters, 'limit' => self::MAX_CHARACTERS]);
        }

        return $text;
    }

    private function runPdfToText(): string
    {
        return new Pdf($this->binary)
            ->setOptions(['enc UTF-8'])
            ->setTimeout(self::TIMEOUT_SECONDS)
            ->setPdf($this->cvFilePath)
            ->text($this->withoutWorkerEnvironment(...));
    }

    /**
     * Symfony Process hérite de $_SERVER, $_ENV et getenv() ; une variable
     * mise à `false` n'est pas transmise.
     */
    private function withoutWorkerEnvironment(Process $process): Process
    {
        $names = array_filter(array_keys($_SERVER + $_ENV + getenv()), is_string(...));

        return $process->setEnv(array_fill_keys($names, false));
    }

    /**
     * @param array<string, int|null> $details
     */
    private function unavailable(string $reason, array $details = []): null
    {
        $this->logger->warning(self::WARNING, ['reason' => $reason, ...$details]);

        return null;
    }
}
