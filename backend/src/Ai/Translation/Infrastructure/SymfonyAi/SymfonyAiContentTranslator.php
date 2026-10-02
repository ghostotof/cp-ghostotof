<?php

declare(strict_types=1);

namespace App\Ai\Translation\Infrastructure\SymfonyAi;

use App\Ai\Translation\Application\ContentTranslatorInterface;
use App\Ai\Translation\Domain\Exception\TranslationUnavailableException;
use App\Ai\Translation\Domain\ValueObject\TranslatedFields;
use App\Ai\Translation\Domain\ValueObject\TranslationRequest;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ExceptionInterface as AgentException;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;

/**
 * Seule classe du projet à importer Symfony\AI (ADR 0004, D1) : tout ce qui
 * tient au bundle — messages, options, forme du résultat — s'arrête ici.
 *
 * Le schéma JSON imposé au modèle est construit à partir des champs demandés
 * (tous requis, aucun autre admis), puis la réponse est re-vérifiée côté
 * serveur (D5) : une clé absente, une valeur vide ou non textuelle, un JSON
 * malformé sont une TranslationUnavailableException, jamais une réponse
 * partielle. Le texte envoyé et reçu n'est jamais journalisé — seuls les
 * jetons, la durée et le nombre de champs le sont. Un échec du fournisseur
 * se journalise par sa classe, son statut HTTP et son type d'erreur, jamais
 * par son message, où le bridge recopie le corps de la réponse (issue #269).
 */
final readonly class SymfonyAiContentTranslator implements ContentTranslatorInterface
{
    private const string SCHEMA_NAME = 'translated_fields';

    public function __construct(
        #[Autowire(service: 'ai.agent.translator')]
        private AgentInterface $agent,
        private LoggerInterface $logger,
    ) {
    }

    public function translate(TranslationRequest $request): TranslatedFields
    {
        $startedAt = hrtime(true);

        try {
            $execution = $this->agent->call(
                new MessageBag(Message::ofUser($this->userMessage($request))),
                ['response_format' => $this->responseFormat($request)],
            );
            $content = $execution->getContent();
            $tokenUsage = $execution->getMetadata()->get('token_usage');
        } catch (PlatformException|AgentException|HttpClientException $exception) {
            // Jamais le message : le bridge y recopie le corps de la réponse du
            // fournisseur, qui peut citer l'entrée (issue #269).
            $this->logger->error('Assistant de traduction : le fournisseur a échoué.', [
                'exception' => $exception::class,
                'serverErrorStatus' => $this->serverErrorStatus($exception),
                'providerErrorType' => $this->providerErrorType($exception),
            ]);

            throw new TranslationUnavailableException();
        }

        $fields = $this->validatedFields($content, $request->fieldNames());

        $this->logger->info('Assistant de traduction : traduction produite.', [
            'fieldCount' => \count($fields),
            'durationMs' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'promptTokens' => $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getPromptTokens() : null,
            'completionTokens' => $tokenUsage instanceof TokenUsageInterface ? $tokenUsage->getCompletionTokens() : null,
        ]);

        return new TranslatedFields($fields);
    }

    /**
     * Le prompt système (config/ai/prompts/translator.txt) fixe les règles ;
     * le message utilisateur ne porte que la direction et le dictionnaire.
     */
    private function userMessage(TranslationRequest $request): string
    {
        return \sprintf(
            "Translate the values of the following JSON object from %s to %s.\n\n%s",
            $request->sourceLocale->value,
            $request->targetLocale->value,
            json_encode($request->fields, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Schéma de sortie structurée, au format attendu par le bridge (qui le
     * traduit en `output_config.format` pour l'API Anthropic).
     *
     * @return array<string, mixed>
     */
    private function responseFormat(TranslationRequest $request): array
    {
        $properties = [];
        foreach ($request->fieldNames() as $name) {
            $properties[$name] = ['type' => 'string'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => self::SCHEMA_NAME,
                'schema' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $request->fieldNames(),
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /**
     * La plateforme réelle rend un tableau déjà décodé (sortie structurée) ;
     * un double de test, ou un bridge sans conversion, rend le JSON en texte.
     * Les deux formes sont admises, le reste est refusé.
     *
     * @param list<string> $expectedNames
     *
     * @return array<string, string>
     */
    private function validatedFields(mixed $content, array $expectedNames): array
    {
        if (\is_string($content)) {
            try {
                $content = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw $this->rejected('JSON malformé');
            }
        }

        if (!\is_array($content)) {
            throw $this->rejected('réponse non structurée');
        }

        $fields = [];
        foreach ($expectedNames as $name) {
            $value = $content[$name] ?? null;
            if (!\is_string($value) || '' === trim($value)) {
                throw $this->rejected(\sprintf('champ "%s" absent, vide ou non textuel', $name));
            }
            $fields[$name] = $value;
        }

        return $fields;
    }

    private function rejected(string $reason): TranslationUnavailableException
    {
        $this->logger->error('Assistant de traduction : réponse du modèle refusée.', ['reason' => $reason]);

        return new TranslationUnavailableException();
    }

    /**
     * Statut HTTP d'une erreur serveur du fournisseur : le bridge ne transmet
     * de statut que pour un 5xx (ServerException). 400, 401 et 429 ont chacun
     * leur classe, qui suffit à les reconnaître.
     */
    private function serverErrorStatus(\Throwable $exception): ?int
    {
        return $exception instanceof ServerException ? $exception->getStatusCode() : null;
    }

    /**
     * Type d'erreur Anthropic (`not_found_error`, `permission_error`…) : sans
     * lui, un modèle retiré (404) ou une clé privée d'un droit (403) se
     * réduisent à une RuntimeException indiscernable d'une réponse vide. Le
     * bridge l'écrit en tête de son message (« API Error [<type>]: "…" ») ;
     * seul ce mot-clé est capturé, par une expression ancrée, jamais ce qui
     * suit. Ce format est celui de symfony/ai-anthropic-platform 0.13.0 :
     * BackofficeTranslationResourceTest le vérifie à travers le vrai bridge,
     * et rougit si une montée de version le change.
     */
    private function providerErrorType(\Throwable $exception): ?string
    {
        if (1 === preg_match('/^(?:Server error\. )?API Error \[([a-z_]{1,40})\]/', $exception->getMessage(), $matches)) {
            return $matches[1];
        }

        return null;
    }
}
