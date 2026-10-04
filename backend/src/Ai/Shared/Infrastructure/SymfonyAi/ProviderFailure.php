<?php

declare(strict_types=1);

namespace App\Ai\Shared\Infrastructure\SymfonyAi;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

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
 *   et le bridge Scaleway « Error "<type>": », hors flux seulement.
 *
 * La raison (ProviderFailureReason) se déduit de ces indices, du plus sûr au
 * moins sûr : la classe d'exception, que le bridge type lui-même, puis le
 * statut, puis le type. C'est elle qui renseigne un échec en flux, où le
 * bridge ne transmet aucun type lisible.
 *
 * Ces formats sont ceux de symfony/ai-*-platform 0.13.0 : ProviderFailureTest
 * les obtient des vrais ResultConverter et rougit si une montée de version les
 * change. Importer Symfony\AI ici est admis par ADR 0004 D1 (amendée) : seules
 * les exceptions du bridge sont lues, jamais un message ni un agent.
 */
final readonly class ProviderFailure
{
    private const string STATUS_PATTERN = '/^Unexpected response code (\d{3})\b/';
    private const string ERROR_TYPE_PATTERN = '/^(?:(?:Server error\. )?API Error \[(?<anthropic>[a-z0-9_]{1,40})\]|Error "(?<scaleway>[a-z0-9_]{1,40})": )/';

    /** Ce qu'écrit le bridge Scaleway quand la réponse n'a ni type ni code : un bouche-trou, pas un type. */
    private const string PLACEHOLDER_TYPE = 'unknown';

    /** Types d'erreur connus des deux fournisseurs, quand ni la classe ni le statut ne suffisent. */
    private const array REASON_BY_ERROR_TYPE = [
        'authentication_error' => ProviderFailureReason::Authentication,
        'permission_error' => ProviderFailureReason::PermissionDenied,
        'permission_denied' => ProviderFailureReason::PermissionDenied,
        'not_found_error' => ProviderFailureReason::ModelNotFound,
        'model_not_found' => ProviderFailureReason::ModelNotFound,
        'rate_limit_error' => ProviderFailureReason::RateLimited,
        'invalid_request_error' => ProviderFailureReason::InputRejected,
        'request_too_large' => ProviderFailureReason::InputRejected,
        'overloaded_error' => ProviderFailureReason::ServerError,
        'api_error' => ProviderFailureReason::ServerError,
        'server_error' => ProviderFailureReason::ServerError,
    ];

    private function __construct(
        /** @var class-string<\Throwable> */
        public string $exceptionClass,
        public ?int $status,
        public ?string $errorType,
        public ProviderFailureReason $reason,
        /** Fichier et ligne de la levée : vendor/ pour le bridge, un fichier du projet pour un bogue de câblage. */
        public string $origin,
    ) {
    }

    public static function from(\Throwable $exception): self
    {
        $status = self::status($exception);
        $errorType = self::errorType($exception->getMessage());

        return new self(
            $exception::class,
            $status,
            $errorType,
            self::reasonFromClass($exception) ?? self::reasonFromStatus($status) ?? self::REASON_BY_ERROR_TYPE[$errorType ?? ''] ?? ProviderFailureReason::Unknown,
            basename($exception->getFile()).':'.$exception->getLine(),
        );
    }

    /**
     * Les clés sont celles des deux journaux (canal par défaut pour le
     * traducteur, `ai_usage` pour l'assistant) : une même requête jq les lit.
     *
     * @return array{exception: class-string<\Throwable>, providerStatus: ?int, providerErrorType: ?string, providerFailure: string, origin: string}
     */
    public function toLogContext(): array
    {
        return [
            'exception' => $this->exceptionClass,
            'providerStatus' => $this->status,
            'providerErrorType' => $this->errorType,
            'providerFailure' => $this->reason->value,
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

        $type = '' !== ($matches['scaleway'] ?? '') ? $matches['scaleway'] : $matches['anthropic'];

        return self::PLACEHOLDER_TYPE === $type ? null : $type;
    }

    /** La classe est l'indice le plus sûr : le bridge l'a choisie d'après le statut et le corps. */
    private static function reasonFromClass(\Throwable $exception): ?ProviderFailureReason
    {
        return match (true) {
            $exception instanceof AuthenticationException => ProviderFailureReason::Authentication,
            $exception instanceof ModelNotFoundException => ProviderFailureReason::ModelNotFound,
            $exception instanceof RateLimitExceededException => ProviderFailureReason::RateLimited,
            $exception instanceof BadRequestException,
            $exception instanceof ExceedContextSizeException,
            $exception instanceof ContentFilterException => ProviderFailureReason::InputRejected,
            $exception instanceof IncompleteStreamException,
            $exception instanceof TransportExceptionInterface => ProviderFailureReason::Interrupted,
            $exception instanceof ServerException => ProviderFailureReason::ServerError,
            default => null,
        };
    }

    private static function reasonFromStatus(?int $status): ?ProviderFailureReason
    {
        return match (true) {
            null === $status => null,
            401 === $status => ProviderFailureReason::Authentication,
            403 === $status => ProviderFailureReason::PermissionDenied,
            404 === $status => ProviderFailureReason::ModelNotFound,
            429 === $status => ProviderFailureReason::RateLimited,
            400 === $status, 413 === $status, 422 === $status => ProviderFailureReason::InputRejected,
            $status >= 500 => ProviderFailureReason::ServerError,
            default => null,
        };
    }
}
