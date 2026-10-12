<?php

declare(strict_types=1);

namespace App\Tests\Ai\Assistant\Infrastructure\RateLimiter;

use App\Ai\Assistant\Application\AssistantRateLimiterInterface;
use App\Ai\Assistant\Application\CareerAssistantInterface;
use App\Ai\Assistant\Domain\Exception\AssistantRateLimitExceededException;
use App\Ai\Assistant\Domain\ValueObject\AnswerUsage;
use App\Ai\Assistant\Domain\ValueObject\Conversation;
use App\Ai\Assistant\Domain\ValueObject\ConversationMessage;
use App\Ai\Assistant\Domain\ValueObject\Role;
use App\Ai\Assistant\Infrastructure\RateLimiter\QuotaGuardedCareerAssistant;
use App\Ai\Assistant\Infrastructure\RateLimiter\UnauthenticatedAssistantCallException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Domain\Security\AccountReference;
use App\Tests\Ai\Translation\Support\InMemoryLogger;
use ArrayObject;
use DateTimeImmutable;
use Generator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Le quota (spec 0005 D6) est consommé une fois par appel, pour le compte
 * authentifié, avant que l'assistant réel ne contacte le fournisseur. Le
 * compte est désigné par son UUID, clé du quota comme du journal, jamais par
 * son identifiant de connexion (issue #363).
 */
final class QuotaGuardedCareerAssistantTest extends TestCase
{
    private const string ACCOUNT_ID = '01890a5d-ac96-774b-bcce-b302099a8057';
    private const string LOGIN_IDENTIFIER = 'jean.dupont';

    /**
     * Appels reçus par les deux doublures, dans l'ordre.
     *
     * @var ArrayObject<int, string>
     */
    private ArrayObject $journal;

    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->journal = new ArrayObject();
        $this->logger = new InMemoryLogger();
    }

    public function testTheQuotaOfTheAuthenticatedAccountIsConsumedBeforeTheCall(): void
    {
        $answer = implode('', iterator_to_array($this->guarded(accepts: true)->answer($this->conversation(), Locale::FR), false));

        self::assertSame('réponse', $answer);
        self::assertSame(['consume:'.self::ACCOUNT_ID, 'answer'], $this->journal->getArrayCopy());
    }

    public function testTheUsageOfTheDecoratedAssistantIsPassedThrough(): void
    {
        $stream = $this->guarded(accepts: true)->answer($this->conversation(), Locale::FR);
        iterator_to_array($stream, false);

        self::assertSame(7, $stream->getReturn()->completionTokens);
    }

    public function testAnExhaustedQuotaNeverReachesTheDecoratedAssistant(): void
    {
        try {
            $this->guarded(accepts: false)->answer($this->conversation(), Locale::FR);
            self::fail('Une exception était attendue.');
        } catch (AssistantRateLimitExceededException) {
            self::assertSame(['consume:'.self::ACCOUNT_ID], $this->journal->getArrayCopy());
        }
    }

    /**
     * Audit de la tâche 3, F5 : un refus de quota journalisé par le noyau en
     * `info` ne sort jamais d'un pod de production. Il est donc tracé sur
     * `ai_usage`, à niveau fixe, avec le compte — rien du contenu (D10).
     */
    public function testARefusalIsLoggedWithTheAccountAndNothingOfTheContent(): void
    {
        try {
            $this->guarded(accepts: false)->answer($this->conversation(), Locale::FR);
            self::fail('Une exception était attendue.');
        } catch (AssistantRateLimitExceededException) {
        }

        self::assertCount(1, $this->logger->records);
        self::assertSame('info', $this->logger->records[0]['level']);
        self::assertSame(['outcome' => 'rate-limited', 'userId' => self::ACCOUNT_ID], $this->logger->records[0]['context']);
        self::assertStringNotContainsString('Question', $this->logger->dump());
        self::assertStringNotContainsString(self::LOGIN_IDENTIFIER, $this->logger->dump());
    }

    public function testAnAcceptedCallIsNotLoggedHere(): void
    {
        $this->guarded(accepts: true)->answer($this->conversation(), Locale::FR);

        self::assertSame([], $this->logger->records);
    }

    /**
     * La route est réservée à ROLE_TRUSTED par l'access_control : sans compte,
     * c'est un défaut de câblage, jamais un appel gratuit.
     */
    public function testWithoutAnAuthenticatedAccountNothingIsCalled(): void
    {
        $guarded = new QuotaGuardedCareerAssistant($this->decorated(), $this->rateLimiter(true), new TokenStorage(), $this->logger);

        try {
            $guarded->answer($this->conversation(), Locale::FR);
            self::fail('Une exception était attendue.');
        } catch (UnauthenticatedAssistantCallException) {
            self::assertSame([], $this->journal->getArrayCopy());
        }
    }

    /**
     * Un utilisateur qui n'est pas un compte (aucun UUID à donner) n'est
     * jamais compté sous son identifiant : c'est un défaut de câblage.
     */
    public function testAUserWithoutAccountIdIsNeverCountedUnderItsIdentifier(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser(self::LOGIN_IDENTIFIER, null, ['ROLE_TRUSTED']), 'api', ['ROLE_TRUSTED']));
        $guarded = new QuotaGuardedCareerAssistant($this->decorated(), $this->rateLimiter(true), $tokenStorage, $this->logger);

        try {
            $guarded->answer($this->conversation(), Locale::FR);
            self::fail('Une exception était attendue.');
        } catch (UnauthenticatedAssistantCallException) {
            self::assertSame([], $this->journal->getArrayCopy());
        }
    }

    private function guarded(bool $accepts): QuotaGuardedCareerAssistant
    {
        $tokenStorage = new TokenStorage();
        $account = new readonly class(self::LOGIN_IDENTIFIER, self::ACCOUNT_ID) implements UserInterface, AccountReference {
            public function __construct(private string $identifier, private string $id)
            {
            }

            public function accountId(): string
            {
                return $this->id;
            }

            public function getRoles(): array
            {
                return ['ROLE_TRUSTED'];
            }

            public function getUserIdentifier(): string
            {
                \assert('' !== $this->identifier);

                return $this->identifier;
            }
        };
        $tokenStorage->setToken(new UsernamePasswordToken($account, 'api', ['ROLE_TRUSTED']));

        return new QuotaGuardedCareerAssistant($this->decorated(), $this->rateLimiter($accepts), $tokenStorage, $this->logger);
    }

    private function decorated(): CareerAssistantInterface
    {
        return new readonly class($this->journal) implements CareerAssistantInterface {
            /** @param ArrayObject<int, string> $journal */
            public function __construct(private ArrayObject $journal)
            {
            }

            public function answer(Conversation $conversation, Locale $locale): Generator
            {
                $this->journal[] = 'answer';

                return $this->fragments();
            }

            /** @return Generator<int, string, mixed, AnswerUsage> */
            private function fragments(): Generator
            {
                yield 'réponse';

                return new AnswerUsage(promptTokens: 3, completionTokens: 7, durationMs: 1);
            }
        };
    }

    private function rateLimiter(bool $accepts): AssistantRateLimiterInterface
    {
        return new readonly class($this->journal, $accepts) implements AssistantRateLimiterInterface {
            /** @param ArrayObject<int, string> $journal */
            public function __construct(private ArrayObject $journal, private bool $accepts)
            {
            }

            public function consume(string $accountId): void
            {
                $this->journal[] = 'consume:'.$accountId;
                if (!$this->accepts) {
                    throw new AssistantRateLimitExceededException(new DateTimeImmutable('+1 hour'));
                }
            }
        };
    }

    private function conversation(): Conversation
    {
        return new Conversation([new ConversationMessage(Role::User, 'Question ?')]);
    }
}
