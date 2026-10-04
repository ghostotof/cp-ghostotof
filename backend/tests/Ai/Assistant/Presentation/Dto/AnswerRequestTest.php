<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Presentation\Dto;

use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Presentation\Dto\AnswerRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Garde anti-abus du DTO (audit de la tâche 3, constat F6) : un corps de
 * 128 Kio porte ~4 000 messages minuscules, et le Validator les parcourait
 * tous (~113 ms mesurés) avant que le VO n'en refuse plus de 11. Le plafond
 * arrête la validation avant `Assert\All`. Il reste large, pour qu'une
 * conversation simplement trop longue garde le 422 typé du VO.
 */
final class AnswerRequestTest extends KernelTestCase
{
    public function testAnAbsurdNumberOfMessagesStopsTheValidationBeforeEachMessageIsChecked(): void
    {
        $request = new AnswerRequest('fr', array_fill(0, 4000, ['role' => 'system', 'content' => 'x']));

        $violations = $this->validator()->validate($request);

        self::assertCount(1, $violations);
        self::assertSame('messages', $violations->get(0)->getPropertyPath());
    }

    public function testTheCapLeavesAConversationSlightlyTooLongToTheDomainObject(): void
    {
        $messages = array_map(
            static fn (int $rank): array => ['role' => 0 === $rank % 2 ? 'user' : 'assistant', 'content' => 'Message '.$rank],
            range(0, Conversation::MAX_MESSAGES + 1),
        );

        self::assertCount(0, $this->validator()->validate(new AnswerRequest('fr', $messages)));
    }

    public function testFiftyMessagesAreTheLastOnesTheValidatorWalks(): void
    {
        $messages = array_fill(0, AnswerRequest::MAX_MESSAGES_VALIDATED, ['role' => 'user', 'content' => 'x']);

        self::assertCount(0, $this->validator()->validate(new AnswerRequest('fr', $messages)));
        self::assertCount(1, $this->validator()->validate(new AnswerRequest('fr', [...$messages, ['role' => 'user', 'content' => 'x']])));
    }

    private function validator(): ValidatorInterface
    {
        self::bootKernel();

        return self::getContainer()->get(ValidatorInterface::class);
    }
}
