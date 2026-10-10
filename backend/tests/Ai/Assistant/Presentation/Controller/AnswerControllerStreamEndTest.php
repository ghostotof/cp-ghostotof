<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Presentation\Controller;

use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Domain\Exception\AssistantUnavailableException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Presentation\Controller\AnswerController;
use App\Ai\Assistant\Presentation\Dto\AnswerRequest;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Closure;
use Generator;
use PHPUnit\Framework\TestCase;

/**
 * Issue #318 : une fois le 200 envoyé, le flux se termine toujours par un
 * événement final.
 *
 * Toute panne du fournisseur, quelle qu'en soit l'exception, devient une
 * AssistantUnavailableException dans l'assistant (SymfonyAiCareerAssistantTest).
 * Ce qui reste au contrôleur, et que ce test fixe : la traduire en `error`, et
 * ne jamais lever lui-même en encodant un fragment.
 *
 * Test unitaire et non fonctionnel : aucune réponse du transport simulé ne
 * produit un fragment en UTF-8 invalide, le bridge décodant du JSON. L'assistant
 * est donc remplacé par un générateur qui rend ce qu'on veut.
 */
final class AnswerControllerStreamEndTest extends TestCase
{
    public function testAnAssistantUnavailableExceptionAfterADeltaEndsWithAnErrorEvent(): void
    {
        $output = $this->send(static function (): Generator {
            yield 'Il a ';

            throw new AssistantUnavailableException();
        });

        self::assertSame(
            "event: delta\ndata: {\"text\":\"Il a \"}\n\n"
            ."event: error\ndata: {\"reason\":\"assistant-unavailable\"}\n\n",
            $output,
        );
    }

    /**
     * Le seul échec propre au contrôleur serait son json_encode, sur un
     * fragment en UTF-8 invalide. Il ne doit pas en être un : l'octet est
     * remplacé par U+FFFD et la réponse se termine normalement.
     */
    public function testAFragmentInInvalidUtf8IsSubstitutedAndTheStreamEndsWithDone(): void
    {
        $output = $this->send(static function (): Generator {
            yield "Il a \xB1";

            return new AnswerUsage(7, 2, 5);
        });

        self::assertSame(
            "event: delta\ndata: {\"text\":\"Il a \u{FFFD}\"}\n\n"
            ."event: done\ndata: {\"promptTokens\":7,\"completionTokens\":2,\"durationMs\":5}\n\n",
            $output,
        );
    }

    /**
     * @param Closure():Generator<int, string, mixed, AnswerUsage> $stream
     */
    private function send(Closure $stream): string
    {
        $assistant = new readonly class($stream) implements CareerAssistantInterface {
            /** @param Closure():Generator<int, string, mixed, AnswerUsage> $stream */
            public function __construct(private Closure $stream)
            {
            }

            public function answer(Conversation $conversation, Locale $locale): Generator
            {
                return ($this->stream)();
            }
        };
        $controller = new AnswerController($assistant);

        $response = $controller(new AnswerRequest('fr', [
            ['role' => 'user', 'content' => 'Quel est son domaine ?'],
        ]));

        ob_start();
        try {
            $response->sendContent();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }
}
