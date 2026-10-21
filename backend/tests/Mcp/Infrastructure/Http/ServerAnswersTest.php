<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Http;

use App\Mcp\Infrastructure\Http\ServerAnswers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * La provenance d'une réponse de `/api/mcp` (audit de #675, C9) : reconnue par
 * son objet, si bien qu'une réponse remplacée après coup — même identique —
 * n'est plus celle du serveur.
 */
final class ServerAnswersTest extends TestCase
{
    public function testARecordedResponseIsRecognised(): void
    {
        $answers = new ServerAnswers();
        $response = new Response('{}', 200, ['Content-Type' => 'application/json']);

        self::assertSame($response, $answers->record($response));
        self::assertTrue($answers->recorded($response));
    }

    public function testAnEqualResponseThatReplacedItIsNot(): void
    {
        $answers = new ServerAnswers();
        $answers->record(new Response('{}', 404, ['Content-Type' => 'application/json']));

        self::assertFalse($answers->recorded(new Response('{}', 404, ['Content-Type' => 'application/json'])));
    }

    public function testResetForgetsEveryResponse(): void
    {
        $answers = new ServerAnswers();
        $response = $answers->record(new Response());

        $answers->reset();

        self::assertFalse($answers->recorded($response));
    }
}
