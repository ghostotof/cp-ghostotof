<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Messenger;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\AccountInvitationDeliveryException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Consommé par `make consume` (messenger:consume async -vv). Depuis le point
 * d'audit C2 (décision D3), c'est ici — et non plus dans CpgUserInviter — que
 * l'unique PasswordSetupToken du compte est (re)créé, puis l'e-mail
 * d'invitation construit et envoyé (rendu Twig à la charte du site,
 * templates/emails/account_invitation.*.twig).
 *
 * Faire naître le jeton dans le handler garantit qu'il ne transite jamais par
 * le message Messenger (donc jamais par le failure_transport) : sa valeur en
 * clair ne quitte ce processus que dans le lien de l'e-mail.
 *
 * Toutes les chaînes visibles par le destinataire sont localisées ici (fr/en)
 * et passées en contexte : le template reste une pure mise en page.
 */
#[AsMessageHandler]
final readonly class SendAccountInvitationHandler
{
    /** Durée de validité du jeton de définition de mot de passe. */
    private const string TOKEN_LIFETIME = '+48 hours';

    public function __construct(
        private MailerInterface $mailer,
        private CpgUserRepositoryInterface $cpgUserRepository,
        private PasswordSetupTokenRepositoryInterface $passwordSetupTokenRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire(param: 'app.contact_sender_email')]
        private string $senderEmail,
        #[Autowire(param: 'app.frontend_base_url')]
        private string $frontendBaseUrl,
    ) {
    }

    public function __invoke(SendAccountInvitationMessage $message): void
    {
        $user = $this->cpgUserRepository->findOneById($message->userId);

        if (null === $user || !$user->isPendingActivation()) {
            // Compte supprimé entre le dispatch et la consommation, ou mot de
            // passe déjà défini : rien à faire, et surtout pas de retry — on
            // sort sans exception.
            $this->logger->warning('Invitation ignorée : compte introuvable ou déjà activé.', [
                'userId' => $message->userId,
            ]);

            return;
        }

        $recipientEmail = $user->getEmail();
        // isPendingActivation() implique invitedAt non null, donc un e-mail posé
        // par CpgUserInviter::invite() : l'assertion l'explicite pour l'analyse
        // statique.
        \assert(null !== $recipientEmail);

        $locale = Locale::from($message->locale);
        $clearToken = bin2hex(random_bytes(32));

        // Un seul jeton actif à la fois : la purge de l'ancien et l'insertion
        // du nouveau sont atomiques (un retry qui échouerait entre les deux ne
        // doit pas laisser le compte sans jeton).
        $this->entityManager->wrapInTransaction(function () use ($user, $clearToken): void {
            $this->passwordSetupTokenRepository->deleteForUser($user);
            $this->passwordSetupTokenRepository->save(new PasswordSetupToken(
                $user,
                hash('sha256', $clearToken),
                $this->clock->now()->modify(self::TOKEN_LIFETIME),
            ));
        });

        $setupUrl = sprintf(
            '%s/%s/set-password/%s',
            rtrim($this->frontendBaseUrl, '/'),
            $locale->value,
            $clearToken,
        );

        $email = (new TemplatedEmail())
            ->from($this->senderEmail)
            ->to($recipientEmail)
            ->subject($this->subjectFor($locale))
            ->htmlTemplate('emails/account_invitation.html.twig')
            ->textTemplate('emails/account_invitation.txt.twig')
            ->context([
                'username' => $user->getUsername(),
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
