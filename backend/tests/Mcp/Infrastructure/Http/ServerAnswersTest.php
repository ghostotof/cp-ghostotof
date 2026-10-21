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
    public function testAnIssuedResponseIsRecognised(): void
    {
        $answers = new ServerAnswers();
        $response = new Response('{}', 200, ['Content-Type' => 'application/json']);

        self::assertSame($response, $answers->issue($response));
        self::assertTrue($answers->issued($response));
    }

    public function testAnEqualResponseThatReplacedItIsNot(): void
    {
        $answers = new ServerAnswers();
        $answers->issue(new Response('{}', 404, ['Content-Type' => 'application/json']));

        self::assertFalse($answers->issued(new Response('{}', 404, ['Content-Type' => 'application/json'])));
    }

    public function testResetForgetsEveryResponse(): void
    {
        $answers = new ServerAnswers();
        $response = $answers->issue(new Response());

        $answers->reset();

        self::assertFalse($answers->issued($response));
    }
}
