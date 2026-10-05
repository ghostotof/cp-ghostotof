<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Mailer;

use App\Shared\Infrastructure\Mailer\MailerTransportFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

/**
 * Issue #356 (audit I4) : une réponse SMTP ou de l'API Scaleway peut citer
 * l'expéditeur ou le destinataire, et le transport la recopie dans son
 * message. Seuls la classe et un code numérique en sont extraits.
 */
final class MailerTransportFailureTest extends TestCase
{
    private const string ADDRESS = 'sentinel.recipient@example.com';

    public function testAnSmtpRejectionKeepsItsReplyCodeNeverItsText(): void
    {
        $failure = MailerTransportFailure::from(new UnexpectedResponseException(
            \sprintf('Expected response code "250" but got code "550", with message "550 5.1.1 <%s>: Recipient address rejected".', self::ADDRESS),
            550,
        ));

        self::assertSame(UnexpectedResponseException::class, $failure->exceptionClass);
        self::assertSame(550, $failure->code);
    }

    /**
     * Le transport API de Scaleway recopie le corps de la réponse dans son
     * message et ne pose aucun code : le statut HTTP se lit sur la réponse.
     */
    public function testAnApiRejectionKeepsItsHttpStatusNeverItsBody(): void
    {
        $response = new MockHttpClient(new MockResponse(
            \sprintf('{"message":"invalid recipient %s"}', self::ADDRESS),
            ['http_code' => 400],
        ))->request('POST', 'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails');

        $failure = MailerTransportFailure::from(new HttpTransportException(
            \sprintf('Unable to send an email: invalid recipient %s (code 400).', self::ADDRESS),
            $response,
        ));

        self::assertSame(HttpTransportException::class, $failure->exceptionClass);
        self::assertSame(400, $failure->code);
    }

    /**
     * « Could not reach the remote Scaleway server » : la réponse n'a pas
     * de statut à donner, la lire relancerait l'échec du client HTTP.
     */
    public function testAnUnreachableApiHasNoCode(): void
    {
        $response = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host.']))
            ->request('POST', 'https://api.scaleway.com/transactional-email/v1alpha1/regions/fr-par/emails');

        $failure = MailerTransportFailure::from(new HttpTransportException('Could not reach the remote Scaleway server.', $response));

        self::assertNull($failure->code);
    }

    public function testATransportFailureWithoutCodeHasNone(): void
    {
        self::assertNull(MailerTransportFailure::from(new TransportException('Connection could not be established.'))->code);
    }
}
