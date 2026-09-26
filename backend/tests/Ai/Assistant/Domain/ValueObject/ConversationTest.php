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
 * Invariants de la conversation : structure et bornes de coût (spec 0005 D6 —
 * nombre de messages, longueurs, alternance, premier et dernier message de la
 * personne). Chaque borne est testée à sa limite exacte, acceptée, puis un
 * cran au-delà, refusée.
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

    /**
     * La borne est de 12, mais l'alternance stricte entre un premier et un
     * dernier message de la personne rend le compte impair : 11 est la plus
     * longue conversation valide, 13 la première que seul le compte refuse.
     */
    public function testElevenMessagesAreAccepted(): void
    {
        self::assertCount(11, new Conversation($this->alternating(11)));
    }

    public function testThirteenMessagesAreRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new Conversation($this->alternating(13));
    }

    public function testAUserMessageOfAThousandCharactersIsAccepted(): void
    {
        self::assertSame(1000, mb_strlen(new ConversationMessage(Role::User, str_repeat('é', 1000))->content));
    }

    /**
     * En caractères et non en octets : `é` fait deux octets, 1 001 d'entre
     * eux dépassent la borne quelle que soit l'unité, 600 ne la dépassent
     * qu'en octets — le second cas garde la mesure en caractères.
     */
    public function testAUserMessageOfAThousandAndOneCharactersIsRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new ConversationMessage(Role::User, str_repeat('a', 1001));
    }

    public function testTheUserBoundCountsCharactersNotBytes(): void
    {
        self::assertSame(600, mb_strlen(new ConversationMessage(Role::User, str_repeat('é', 600))->content));
    }

    public function testAnAssistantMessageOfFourThousandCharactersIsAccepted(): void
    {
        self::assertSame(4000, mb_strlen(new ConversationMessage(Role::Assistant, str_repeat('a', 4000))->content));
    }

    public function testAnAssistantMessageOfFourThousandAndOneCharactersIsRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new ConversationMessage(Role::Assistant, str_repeat('a', 4001));
    }

    public function testAConversationStartingWithTheAssistantIsRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new Conversation([
            new ConversationMessage(Role::Assistant, 'Bonjour.'),
            new ConversationMessage(Role::User, 'Question ?'),
        ]);
    }

    public function testTwoConsecutiveUserMessagesAreRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new Conversation([
            new ConversationMessage(Role::User, 'Première ?'),
            new ConversationMessage(Role::User, 'Seconde ?'),
        ]);
    }

    public function testTwoConsecutiveAssistantMessagesAreRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new Conversation([
            new ConversationMessage(Role::User, 'Question ?'),
            new ConversationMessage(Role::Assistant, 'Réponse.'),
            new ConversationMessage(Role::Assistant, 'Autre réponse.'),
            new ConversationMessage(Role::User, 'Question ?'),
        ]);
    }

    public function testAConversationEndingWithTheAssistantIsRefused(): void
    {
        $this->expectException(InvalidConversationException::class);

        new Conversation([
            new ConversationMessage(Role::User, 'Question ?'),
            new ConversationMessage(Role::Assistant, 'Réponse.'),
        ]);
    }

    public function testRoleValuesAreTheWireNames(): void
    {
        self::assertSame(['user', 'assistant'], Role::values());
    }

    /**
     * Conversation alternée commençant par la personne, de $count messages.
     *
     * @return list<ConversationMessage>
     */
    private function alternating(int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; ++$i) {
            $messages[] = new ConversationMessage(0 === $i % 2 ? Role::User : Role::Assistant, 'Message '.$i);
        }

        return $messages;
    }
}
