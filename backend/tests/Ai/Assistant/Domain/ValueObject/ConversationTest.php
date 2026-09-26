<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Domain\ValueObject;

use App\Ai\Assistant\Domain\Exception\InvalidConversationException;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\ConversationMessage;
use App\Ai\Assistant\Domain\ValueObject\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Invariants structurels de la conversation. Les bornes de coût (D6 :
 * nombre, longueurs, alternance) arrivent avec la tâche 3 (#262).
 */
final class ConversationTest extends TestCase
{
    public function testKeepsTheMessagesInTheOrderReceived(): void
    {
        $conversation = new Conversation([
            new ConversationMessage(Role::User, 'Première question ?'),
            new ConversationMessage(Role::Assistant, 'Première réponse.'),
            new ConversationMessage(Role::User, 'Seconde question ?'),
        ]);

        self::assertCount(3, $conversation);
        self::assertSame(
            ['Première question ?', 'Première réponse.', 'Seconde question ?'],
            array_map(static fn (ConversationMessage $message): string => $message->content, $conversation->messages()),
        );
    }

    public function testAnEmptyConversationIsRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new Conversation([]);
    }

    #[DataProvider('blankContents')]
    public function testABlankMessageIsRefused(string $content): void
    {
        $this->expectException(InvalidConversationException::class);

        new ConversationMessage(Role::User, $content);
    }

    /** @return iterable<string, array{string}> */
    public static function blankContents(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['   '];
        yield 'retours à la ligne et tabulation' => ["\n\t\n"];
    }

    public function testRoleValuesAreTheWireNames(): void
    {
        self::assertSame(['user', 'assistant'], Role::values());
    }
}
