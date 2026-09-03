<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Messenger;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Exception\AccountInvitationDeliveryException;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consommé par `make consume` (messenger:consume async -vv). Construit et
 * envoie l'e-mail d'invitation à définir son mot de passe, rendu par Twig à la
 * charte du site (templates/emails/account_invitation.*.twig).
 *
 * Toutes les chaînes visibles par le destinataire sont localisées ici (fr/en)
 * et passées en contexte : le template reste une pure mise en page.
 */
#[AsMessageHandler]
final readonly class SendAccountInvitationHandler
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire(param: 'app.contact_sender_email')]
        private string $senderEmail,
        #[Autowire(param: 'app.frontend_base_url')]
        private string $frontendBaseUrl,
    ) {
    }

    public function __invoke(SendAccountInvitationMessage $message): void
    {
        $locale = Locale::from($message->locale);

        $setupUrl = sprintf(
            '%s/%s/set-password/%s',
            rtrim($this->frontendBaseUrl, '/'),
            $locale->value,
            $message->clearToken,
        );

        $email = (new TemplatedEmail())
            ->from($this->senderEmail)
            ->to($message->recipientEmail)
            ->subject($this->subjectFor($locale))
            ->htmlTemplate('emails/account_invitation.html.twig')
            ->textTemplate('emails/account_invitation.txt.twig')
            ->context([
                'username' => $message->username,
                'setupUrl' => $setupUrl,
                'strings' => $this->stringsFor($locale),
            ])
        ;

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            throw AccountInvitationDeliveryException::becauseTransportFailed($exception);
        }
    }

    private function subjectFor(Locale $locale): string
    {
        return match ($locale) {
            Locale::FR => 'Définissez votre mot de passe',
            Locale::EN => 'Set your password',
        };
    }

    /**
     * @return array<string, string>
     */
    private function stringsFor(Locale $locale): array
    {
        return match ($locale) {
            Locale::FR => [
                'heading' => 'Bienvenue',
                'intro' => 'Un accès vient d\'être créé pour vous. Choisissez un mot de passe pour l\'activer.',
                'usernameLabel' => 'Votre identifiant de connexion',
                'cta' => 'Définir mon mot de passe',
                'expiryNote' => 'Ce lien est valable 48 heures.',
                'fallbackNote' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
                'ignoreNote' => 'Si vous n\'êtes pas à l\'origine de cette demande, vous pouvez ignorer cet e-mail.',
            ],
            Locale::EN => [
                'heading' => 'Welcome',
                'intro' => 'An access has just been created for you. Choose a password to activate it.',
                'usernameLabel' => 'Your sign-in username',
                'cta' => 'Set my password',
                'expiryNote' => 'This link is valid for 48 hours.',
                'fallbackNote' => 'If the button does not work, copy this link into your browser:',
                'ignoreNote' => 'If you did not request this, you can safely ignore this email.',
            ],
        };
    }
}
