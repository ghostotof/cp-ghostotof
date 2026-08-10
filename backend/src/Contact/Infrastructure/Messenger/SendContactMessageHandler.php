<?php

declare(strict_types=1);

namespace App\Contact\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageDeliveryException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Consommé par `make consume` (messenger:consume async -vv). Construit et
 * envoie réellement l'email via Symfony Mailer (MAILER_DSN) — tant que le
 * nom de domaine cp-ghostotof.com n'est pas configuré, MAILER_DSN=null://null
 * fait que ce handler s'exécute sans erreur mais n'envoie rien (cf. .env).
 */
#[AsMessageHandler]
final readonly class SendContactMessageHandler
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire(param: 'app.contact_recipient_email')]
        private string $contactRecipientEmail,
    ) {
    }

    public function __invoke(SendContactMessageMessage $message): void
    {
        // From = la boîte de contact elle-même (pas l'email du visiteur) : la
        // plupart des fournisseurs SMTP rejettent ou classent en spam un
        // message dont l'en-tête From ne correspond pas à un domaine
        // authentifié (SPF/DKIM). Le visiteur reste joignable via replyTo,
        // qu'un client mail utilise quand on clique sur "Répondre".
        $email = (new Email())
            ->from($this->contactRecipientEmail)
            ->to($this->contactRecipientEmail)
            ->replyTo(new Address($message->senderEmail, $message->senderName))
            ->subject(sprintf('Nouveau message de contact — %s', $message->senderName))
            ->text($message->body);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw ContactMessageDeliveryException::becauseTransportFailed($exception);
        }
    }
}
