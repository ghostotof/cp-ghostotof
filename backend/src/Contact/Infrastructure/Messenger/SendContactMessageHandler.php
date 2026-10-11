<?php

declare(strict_types=1);

namespace App\Contact\Infrastructure\Messenger;

use App\Contact\Application\Message\SendContactMessageMessage;
use App\Contact\Domain\Exception\ContactMessageDeliveryException;
use App\Shared\Infrastructure\Mailer\MailerSendingFailure;
use App\Shared\Infrastructure\Mailer\MailerTransportFailure;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Throwable;

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
        try {
            $this->mailer->send($this->notificationEmail($message));
        } catch (Throwable $exception) {
            throw $this->deliveryFailure($exception);
        }
    }

    private function notificationEmail(SendContactMessageMessage $message): TemplatedEmail
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
        return (new TemplatedEmail())
            ->from($this->contactSenderEmail)
            ->to($this->contactRecipientEmail)
            ->replyTo(new Address($message->senderEmail, $message->senderName))
            ->subject(\sprintf('Nouveau message de contact — %s', $message->senderName))
            ->htmlTemplate('emails/contact_notification.html.twig')
            ->textTemplate('emails/contact_notification.txt.twig')
            ->context([
                'senderName' => $message->senderName,
                'senderEmail' => $message->senderEmail,
                'body' => $message->body,
            ]);
    }

    /**
     * Ni le message du transport (qui peut citer une adresse, issue #356), ni
     * celui d'une erreur de rendu ou d'une adresse que Mime refuse (qui citent
     * ce que le visiteur a saisi, issue #427) ne doivent atteindre les logs du
     * worker ni son stamp d'erreur : jamais de `previous`. Le réessai n'en
     * change pas : ni l'exception remplacée ni celle-ci ne sont
     * « (un)recoverable », la stratégie du transport décide.
     *
     * Le message Messenger lui-même, sérialisé dans le failure_transport,
     * porte toujours ce qu'il transporte : c'est sa rétention qui le règle
     * (`app:contact:purge-failed-messages`).
     */
    private function deliveryFailure(Throwable $exception): ContactMessageDeliveryException
    {
        if ($exception instanceof TransportExceptionInterface) {
            // La classe et le code seulement.
            $failure = MailerTransportFailure::from($exception);

            return ContactMessageDeliveryException::becauseTransportFailed($failure->exceptionClass, $failure->code);
        }

        return ContactMessageDeliveryException::becauseSendingFailed(MailerSendingFailure::describe($exception));
    }
}
