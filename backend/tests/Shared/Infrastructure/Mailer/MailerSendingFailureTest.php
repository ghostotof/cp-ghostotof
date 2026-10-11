<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mailer;

use App\Shared\Infrastructure\Mailer\MailerSendingFailure;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Mime\Email;
use Twig\Error\RuntimeError;
use Twig\Source;

/**
 * La description sûre d'un échec d'envoi : son type et son emplacement,
 * jamais son message (issues #419, #427). Les deux handlers d'e-mail la
 * vérifient aussi par la vraie chaîne du mailer.
 */
final class MailerSendingFailureTest extends TestCase
{
    public function testAnErrorOutsideTwigIsDescribedByItsFileAndLine(): void
    {
        $line = __LINE__ + 1;
        $description = MailerSendingFailure::describe(new LogicException('sentinel'));

        self::assertSame(\sprintf('LogicException, MailerSendingFailureTest.php:%d', $line), $description);
    }

    public function testATemplateErrorIsDescribedByItsTemplateAndLine(): void
    {
        self::assertSame(
            'Twig\Error\RuntimeError, emails/x.txt.twig:3',
            MailerSendingFailure::describe(new RuntimeError('sentinel', 3, new Source('', 'emails/x.txt.twig'))),
        );
    }

    /** Twig rend -1 quand il ne sait pas la ligne : le gabarit seul, alors. */
    public function testATemplateErrorWithoutAKnownLineNamesTheTemplateOnly(): void
    {
        self::assertSame(
            'Twig\Error\RuntimeError, emails/x.txt.twig',
            MailerSendingFailure::describe(new RuntimeError('sentinel', -1, new Source('', 'emails/x.txt.twig'))),
        );
    }

    public function testATemplateErrorWithoutASourceNamesItsTypeOnly(): void
    {
        self::assertSame(RuntimeError::class, MailerSendingFailure::describe(new RuntimeError('sentinel')));
    }

    /** Une HandlerFailedException par bus traversé : celle du fond est décrite. */
    public function testTheInnermostExceptionOfNestedBusFailuresIsDescribed(): void
    {
        $envelope = new Envelope(new SendEmailMessage(new Email()));
        $failure = new HandlerFailedException($envelope, [
            new HandlerFailedException($envelope, [new RuntimeError('sentinel', 2, new Source('', 'emails/x.html.twig'))]),
        ]);

        self::assertSame('Twig\Error\RuntimeError, emails/x.html.twig:2', MailerSendingFailure::describe($failure));
    }
}
