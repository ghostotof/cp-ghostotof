<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Log;

use App\Ai\Translation\Domain\Exception\TranslationRateLimitExceededException;
use App\Contact\Domain\Exception\ContactRateLimitExceededException;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Log\ThrottledRequestAuditListener;
use App\Security\User\Domain\Exception\BaseAccessRateLimitExceededException;
use App\Security\User\Domain\Exception\PasswordSetupRateLimitExceededException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Issue #356 : l'écouteur ne fait que trier — quelle exception de quota
 * anonyme donne quel événement. Le contenu des lignes (IP, chemin, aucun
 * sujet) relève de SecurityAuditLoggerTest, le câblage réel de
 * SecurityAuditLogTest.
 */
final class ThrottledRequestAuditListenerTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, string}>
     */
    public static function anonymousQuotaRefusals(): iterable
    {
        $retryAfter = new \DateTimeImmutable('+1 hour');

        yield 'définition de mot de passe' => [new PasswordSetupRateLimitExceededException($retryAfter), 'passwordSetupThrottled'];
        yield 'formulaire de contact' => [new ContactRateLimitExceededException($retryAfter), 'contactThrottled'];
        yield 'palier de base' => [new BaseAccessRateLimitExceededException($retryAfter), 'baseAccessThrottled'];
    }

    #[DataProvider('anonymousQuotaRefusals')]
    public function testEachAnonymousQuotaRefusalHasItsOwnEvent(\Throwable $refusal, string $expectedMethod): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        foreach (['passwordSetupThrottled', 'contactThrottled', 'baseAccessThrottled'] as $method) {
            $auditLogger->expects($method === $expectedMethod ? self::once() : self::never())->method($method);
        }

        (new ThrottledRequestAuditListener($auditLogger))($this->exceptionEvent($refusal));
    }

    /**
     * Le quota du traducteur (ROLE_SUPER) se trace sur `ai_usage`, avec le
     * compte : il n'a rien à faire dans ce tri.
     */
    public function testAnAuthenticatedQuotaRefusalIsNotItsBusiness(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method(self::anything());

        (new ThrottledRequestAuditListener($auditLogger))($this->exceptionEvent(new TranslationRateLimitExceededException(new \DateTimeImmutable('+1 hour'))));
    }

    public function testAnyOtherExceptionIsIgnored(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method(self::anything());

        (new ThrottledRequestAuditListener($auditLogger))($this->exceptionEvent(new \DomainException('Autre chose.')));
    }

    public function testASubRequestIsIgnored(): void
    {
        $auditLogger = $this->createMock(SecurityAuditLoggerInterface::class);
        $auditLogger->expects(self::never())->method(self::anything());

        (new ThrottledRequestAuditListener($auditLogger))($this->exceptionEvent(
            new ContactRateLimitExceededException(new \DateTimeImmutable('+1 hour')),
            HttpKernelInterface::SUB_REQUEST,
        ));
    }

    /**
     * Priorité 0 : au-dessus d'API Platform (-96) et d'ApiProblemResponseListener
     * (-98), qui fixent la réponse du 429 et arrêtent la propagation — en
     * dessous, l'écouteur ne verrait jamais aucun refus.
     */
    public function testItListensToKernelExceptionAbovePropagationStoppers(): void
    {
        $attributes = new \ReflectionClass(ThrottledRequestAuditListener::class)->getAttributes(AsEventListener::class);

        self::assertCount(1, $attributes);
        $listener = $attributes[0]->newInstance();
        self::assertSame(ExceptionEvent::class, $listener->event);
        self::assertSame(0, $listener->priority);
    }

    private function exceptionEvent(\Throwable $throwable, int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create('/api/contact', 'POST'),
            $requestType,
            $throwable,
        );
    }
}
