<?php

declare(strict_types=1);

namespace App\Contact\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageDeliveryException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

/**
 * Consommé par `make consume` (messenger:consume async -vv). Construit et
 * envoie réellement l'email via Symfony Mailer (MAILER_DSN) — en dev,
 * MAILER_DSN=null://null fait que ce handler s'exécute sans erreur mais
 * n'envoie rien ; en préprod/prod le transport est Scaleway TEM (cf. .env).
 */
#[AsMessageHandler]
final readonly class SendContactMessageHandler
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire(param: 'app.contact_sender_email')]
        private string $contactSenderEmail,
        #[Autowire(param: 'app.contact_recipient_email')]
        private string $contactRecipientEmail,
    ) {
    }

    public function __invoke(SendContactMessageMessage $message): void
    {
        // From = une adresse d'envoi dédiée du domaine (jamais l'email du
        // visiteur) : un fournisseur comme Scaleway TEM rejette un From dont
        // le domaine n'est pas validé (SPF/DKIM). Le visiteur reste joignable
        // via replyTo, qu'un client mail utilise au clic sur "Répondre" — et
        // son nom + son email figurent aussi dans le corps du message, pour
        // que le destinataire puisse le recontacter (ex. créer un compte).
        //
        // TemplatedEmail : le corps HTML + texte est rendu par Twig au moment
        // de l'envoi (BodyRenderer, enregistré par twig-bundle), à partir du
        // layout de marque commun (templates/emails/base.*.twig).
        $email = (new TemplatedEmail())
            ->from($this->contactSenderEmail)
            ->to($this->contactRecipientEmail)
            ->replyTo(new Address($message->senderEmail, $message->senderName))
            ->subject(sprintf('Nouveau message de contact — %s', $message->senderName))
            ->htmlTemplate('emails/contact_notification.html.twig')
            ->textTemplate('emails/contact_notification.txt.twig')
            ->context([
                'senderName' => $message->senderName,
                'senderEmail' => $message->senderEmail,
                'body' => $message->body,
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw ContactMessageDeliveryException::becauseTransportFailed($exception);
        }
    }
}
