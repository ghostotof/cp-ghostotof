<?php

declare(strict_types=1);

namespace App\Tests\Ai\Translation\Infrastructure\SymfonyAi;

use App\Ai\Translation\Domain\Exception\TranslationUnavailableException;
use App\Ai\Translation\Domain\ValueObject\TranslationRequest;
use App\Ai\Translation\Infrastructure\SymfonyAi\SymfonyAiContentTranslator;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Tests\Ai\Translation\Support\FakeAgent;
use App\Tests\Ai\Translation\Support\InMemoryLogger;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RuntimeException as PlatformRuntimeException;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

/**
 * Aucun de ces tests ne sort sur le réseau : l'agent est un double en mémoire.
 * Le vrai câblage (ai.yaml, bridge Anthropic) est couvert par le test
 * fonctionnel de la ressource, qui remplace la plateforme dans le conteneur.
 */
final class SymfonyAiContentTranslatorTest extends TestCase
{
    private const string TITLE_FR = 'Panne du broker RabbitMQ';
    private const string IMPACT_FR = 'Le formulaire de contact a répondu 500 pendant `15` minutes.';

    private function request(): TranslationRequest
    {
        return new TranslationRequest(Locale::FR, Locale::EN, ['title' => self::TITLE_FR, 'impact' => self::IMPACT_FR]);
    }

    private function translator(ResultInterface|\Throwable $outcome, ?InMemoryLogger $logger = null): SymfonyAiContentTranslator
    {
        return new SymfonyAiContentTranslator(new FakeAgent($outcome), $logger ?? new InMemoryLogger());
    }

    public function testReturnsTheTranslatedFieldsFromAJsonText(): void
    {
        $translator = $this->translator(new TextResult('{"title":"RabbitMQ broker outage","impact":"The contact form answered 500 for `15` minutes."}'));

        $translated = $translator->translate($this->request());

        self::assertSame([
            'title' => 'RabbitMQ broker outage',
            'impact' => 'The contact form answered 500 for `15` minutes.',
        ], $translated->fields);
    }

    public function testAcceptsAnAlreadyDecodedStructuredResult(): void
    {
        // Sur la vraie plateforme, le PlatformSubscriber de sortie structurée
        // convertit le texte en ObjectResult (tableau) avant que l'agent ne le rende.
        $translator = $this->translator(new ObjectResult(['title' => 'Outage', 'impact' => 'Down.']));

        self::assertSame(['title' => 'Outage', 'impact' => 'Down.'], $translator->translate($this->request())->fields);
    }

    public function testImposesAStrictJsonSchemaListingExactlyTheRequestedFields(): void
    {
        $agent = new FakeAgent(new TextResult('{"title":"a","impact":"b"}'));
        $translator = new SymfonyAiContentTranslator($agent, new InMemoryLogger());

        $translator->translate($this->request());

        $schema = $agent->lastOptions['response_format']['json_schema']['schema'] ?? null;
        self::assertIsArray($schema);
        self::assertSame('object', $schema['type']);
        self::assertSame(['title', 'impact'], array_keys($schema['properties']));
        self::assertSame(['type' => 'string'], $schema['properties']['title']);
        self::assertSame(['title', 'impact'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
    }

    public function testSendsTheSourceTextsAndBothLocalesToTheModel(): void
    {
        $agent = new FakeAgent(new TextResult('{"title":"a","impact":"b"}'));
        $translator = new SymfonyAiContentTranslator($agent, new InMemoryLogger());

        $translator->translate($this->request());

        $text = $agent->lastUserText();
        self::assertStringContainsString(self::TITLE_FR, $text);
        self::assertStringContainsString(self::IMPACT_FR, $text);
        self::assertStringContainsString('fr', $text);
        self::assertStringContainsString('en', $text);
    }

    public function testRefusesAResponseMissingARequestedField(): void
    {
        $translator = $this->translator(new TextResult('{"title":"Outage"}'));

        $this->expectException(TranslationUnavailableException::class);

        $translator->translate($this->request());
    }

    public function testRefusesAnEmptyTranslation(): void
    {
        $translator = $this->translator(new TextResult('{"title":"Outage","impact":"   "}'));

        $this->expectException(TranslationUnavailableException::class);

        $translator->translate($this->request());
    }

    public function testRefusesANonTextualTranslation(): void
    {
        $translator = $this->translator(new TextResult('{"title":"Outage","impact":["Down."]}'));

        $this->expectException(TranslationUnavailableException::class);

        $translator->translate($this->request());
    }

    public function testRefusesMalformedJson(): void
    {
        $translator = $this->translator(new TextResult('Sure! Here is the translation: {"title": ...'));

        $this->expectException(TranslationUnavailableException::class);

        $translator->translate($this->request());
    }

    public function testTurnsAProviderFailureIntoAnUnavailableTranslationAndLogsTheCause(): void
    {
        $logger = new InMemoryLogger();
        $translator = $this->translator(new PlatformRuntimeException('HTTP 529 overloaded'), $logger);

        try {
            $translator->translate($this->request());
            self::fail('Une exception était attendue.');
        } catch (TranslationUnavailableException $exception) {
            self::assertStringNotContainsString('529', $exception->getMessage(), 'Le message du fournisseur ne doit pas remonter au client.');
        }

        self::assertStringContainsString('HTTP 529 overloaded', $logger->dump());
    }

    public function testLogsTokenUsageAndDurationButNeverTheContent(): void
    {
        $logger = new InMemoryLogger();
        $result = new TextResult('{"title":"RabbitMQ broker outage","impact":"Down."}');
        $result->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 321, completionTokens: 45));
        $translator = $this->translator($result, $logger);

        $translator->translate($this->request());

        $info = array_values(array_filter($logger->records, static fn (array $record): bool => 'info' === $record['level']));
        self::assertCount(1, $info);
        self::assertSame(321, $info[0]['context']['promptTokens']);
        self::assertSame(45, $info[0]['context']['completionTokens']);
        self::assertArrayHasKey('durationMs', $info[0]['context']);
        self::assertSame(2, $info[0]['context']['fieldCount']);

        $dump = $logger->dump();
        self::assertStringNotContainsString('RabbitMQ', $dump);
        self::assertStringNotContainsString('formulaire', $dump);
    }
}
