<?php

declare(strict_types=1);

namespace App\Ai\Shared\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Description journalisable d'un échec du fournisseur de modèle, partagée par
 * le traducteur et l'assistant de parcours (issue #308).
 *
 * Le bridge recopie le corps de la réponse du fournisseur dans le message de
 * ses exceptions, et ce corps peut citer l'entrée : le message n'est donc
 * jamais journalisé (ADR 0004, « le contenu n'est jamais journalisé »). Cette
 * classe est la seule à en lire le texte, et n'en extrait que deux jetons
 * ancrés en tête, jamais ce qui suit :
 *
 * - le statut HTTP, par `ServerException::getStatusCode()` (5xx), par la
 *   réponse d'une exception du client HTTP, ou par le préfixe
 *   « Unexpected response code NNN » qu'écrivent les deux bridges en flux ;
 * - le type d'erreur du fournisseur, que le bridge Anthropic écrit
 *   « API Error [<type>] » (précédé de « Server error. » pour une surcharge)
 *   et le bridge Scaleway « Error "<type>": ». Sans lui, un modèle retiré ou
 *   une clé privée d'un droit se réduisent à une RuntimeException anonyme.
 *
 * Ces formats sont ceux de symfony/ai-*-platform 0.13.0 : ProviderFailureTest
 * les obtient des vrais ResultConverter et rougit si une montée de version les
 * change. Importer Symfony\AI ici est admis par ADR 0004 D1 (amendé) : seules
 * les exceptions du bridge sont lues, jamais un message ni un agent.
 */
final readonly class ProviderFailure
{
    private const string STATUS_PATTERN = '/^Unexpected response code (\d{3})\b/';
    private const string ERROR_TYPE_PATTERN = '/^(?:(?:Server error\. )?API Error \[([a-z0-9_]{1,40})\]|Error "([a-z0-9_]{1,40})": )/';

    private function __construct(
        /** @var class-string<\Throwable> */
        public string $exceptionClass,
        public ?int $status,
        public ?string $errorType,
        /** Fichier et ligne de la levée : vendor/ pour le bridge, un fichier du projet pour un bogue de câblage. */
        public string $origin,
    ) {
    }

    public static function from(\Throwable $exception): self
    {
        return new self(
            $exception::class,
            self::status($exception),
            self::errorType($exception->getMessage()),
            basename($exception->getFile()).':'.$exception->getLine(),
        );
    }

    /**
     * Les clés sont celles des deux journaux (canal par défaut pour le
     * traducteur, `ai_usage` pour l'assistant) : une même requête jq les lit.
     *
     * @return array{exception: class-string<\Throwable>, providerStatus: ?int, providerErrorType: ?string, origin: string}
     */
    public function toLogContext(): array
    {
        return [
            'exception' => $this->exceptionClass,
            'providerStatus' => $this->status,
            'providerErrorType' => $this->errorType,
            'origin' => $this->origin,
        ];
    }

    private static function status(\Throwable $exception): ?int
    {
        if ($exception instanceof ServerException) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof HttpExceptionInterface) {
            return $exception->getResponse()->getStatusCode();
        }

        if (1 === preg_match(self::STATUS_PATTERN, $exception->getMessage(), $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private static function errorType(string $message): ?string
    {
        if (1 !== preg_match(self::ERROR_TYPE_PATTERN, $message, $matches)) {
            return null;
        }

        return ($matches[2] ?? '') !== '' ? $matches[2] : $matches[1];
    }
}
