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
 * jetons, la durée et le nombre de champs le sont.
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
            $this->logger->error('Assistant de traduction : le fournisseur a échoué.', [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            throw new TranslationUnavailableException($exception);
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
            } catch (\JsonException $exception) {
                throw $this->rejected('JSON malformé', $exception);
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

    private function rejected(string $reason, ?\Throwable $previous = null): TranslationUnavailableException
    {
        $this->logger->error('Assistant de traduction : réponse du modèle refusée.', ['reason' => $reason]);

        return new TranslationUnavailableException($previous);
    }
}
