<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Messenger;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\AccountInvitationDeliveryException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Shared\Infrastructure\Mailer\MailerSendingFailure;
use App\Shared\Infrastructure\Mailer\MailerTransportFailure;
use DateInterval;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;
use Throwable;

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
    /**
     * Durée de validité du jeton de définition de mot de passe, en heures
     * écoulées : `modify('+48 hours')` compte en heure murale et donnait 49 h
     * ou 47 h à Paris la veille d'un changement d'heure (issue #445).
     */
    private const string TOKEN_LIFETIME = 'PT48H';

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
        $user = $this->cpgUserRepository->findOneById(Uuid::fromString($message->userId));

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

        if ($user->wasReinvitedSince($message->servedGeneration())) {
            // Issue #434 : une relance plus récente a déjà émis son jeton, et
            // son e-mail est peut-être déjà arrivé. Ce message (réessai
            // différé, rejeu du failure_transport) le purgerait : il sort sans
            // exception, donc sans réessai, comme pour un compte activé.
            $this->logger->warning('Invitation ignorée : une invitation plus récente du compte l\'a remplacée.', [
                'userId' => $message->userId,
            ]);

            return;
        }

        $locale = Locale::from($message->locale);
        $clearToken = bin2hex(random_bytes(32));
        $token = new PasswordSetupToken(
            $user,
            hash('sha256', $clearToken),
            $this->clock->now()->add(new DateInterval(self::TOKEN_LIFETIME)),
        );

        // Un seul jeton actif à la fois : la purge de l'ancien et l'insertion
        // du nouveau sont atomiques, aucun état intermédiaire n'est visible.
        // Si l'e-mail ne part pas, ce nouveau jeton est retiré à son tour
        // (issue #425) : le compte reste alors sans lien valable jusqu'au
        // réessai suivant, une relance comprise — choix assumé, un lien
        // qu'on relance est souvent perdu ou douteux.
        $issued = $this->entityManager->wrapInTransaction(function () use ($user, $token, $message): bool {
            // Verrou du compte (issue #435) : en READ COMMITTED, la purge d'un
            // second consommateur (un `messenger:consume failed` lancé à la
            // main pendant que le worker tourne) ne voyait pas le jeton non
            // commité du premier, et deux jetons restaient utilisables. Le
            // second attend ici le commit du premier, puis relit le compte :
            // les contrôles faits plus haut, hors verrou, sont refaits sur
            // l'état que ce verrou garantit.
            $this->entityManager->refresh($user, LockMode::PESSIMISTIC_WRITE);
            if (!$user->isPendingActivation() || $user->wasReinvitedSince($message->servedGeneration())) {
                return false;
            }

            $this->passwordSetupTokenRepository->deleteForUser($user);
            $this->passwordSetupTokenRepository->save($token);

            return true;
        });

        if (true !== $issued) {
            $this->logger->warning('Invitation ignorée : compte activé ou invitation plus récente, constaté sous verrou.', [
                'userId' => $message->userId,
            ]);

            return;
        }

        try {
            $this->mailer->send($this->invitationEmail($user, $recipientEmail, $locale, $clearToken));
        } catch (Throwable $exception) {
            // Avant toute conversion : rien de ce qui suit ne doit pouvoir
            // laisser le jeton en place.
            $this->discardUndeliveredToken($token, $message->userId);

            throw $this->deliveryFailure($exception);
        }
    }

    /**
     * Le jeton n'a été remis à personne : il ne reste pas valable 48 h en
     * attendant une fuite (issue #425). Le réessai de Messenger repasse ici et
     * en émet un nouveau.
     *
     * Une exception de transport ne prouve pas que l'e-mail est resté chez
     * nous (réponse perdue après acceptation, reconnexion SMTP ratée après le
     * `250`) : le lien reçu est alors mort, et le réessai en envoie un autre.
     * Limite acceptée, du côté de la sûreté.
     *
     * Ce jeton seul, jamais tous ceux du compte : un second consommateur (un
     * `messenger:consume failed` lancé à la main pendant que le worker tourne)
     * a pu purger celui-ci et émettre le sien, déjà parti. Le supprimer
     * casserait le lien reçu.
     *
     * Un retrait raté ne remplace pas l'échec de l'envoi, que le worker doit
     * recevoir tel quel (sa stratégie de réessai en dépend) : il est
     * journalisé par la classe de son exception seule, le message d'une
     * exception Doctrine pouvant citer le condensat, et par l'identifiant du
     * jeton, qui n'est pas un secret (la recherche passe par le condensat) et
     * permet de le révoquer seul. Le jeton vit alors jusqu'au prochain envoi
     * réussi, qui le purge, ou jusqu'à son expiration.
     */
    private function discardUndeliveredToken(PasswordSetupToken $token, string $userId): void
    {
        try {
            $this->passwordSetupTokenRepository->remove($token);
        } catch (Throwable $exception) {
            $this->logger->error('Invitation non remise : le retrait de son jeton a échoué, il reste valable jusqu\'au prochain envoi réussi ou à son expiration.', [
                'exceptionClass' => $exception::class,
                'userId' => $userId,
                'tokenId' => $token->getId()->toRfc4122(),
            ]);
        }
    }

    /**
     * Ni l'exception de la construction de l'e-mail (une adresse que Mime
     * refuse, qu'il cite) ni celle du rendu du gabarit, dans send() (un
     * message Twig peut citer l'URL), ni le message du transport (qui peut
     * citer une adresse, issue #356) ne doivent atteindre le stamp d'erreur
     * du worker ni ses logs (issue #419, audit C2) : jamais de `previous`.
     * Le réessai n'en change pas dans ce câblage : ni l'exception remplacée ni
     * celle-ci ne sont « (un)recoverable », la stratégie du transport décide.
     * Limite : une exception de ce genre levée nue par send() (un listener de
     * Symfony\Component\Mailer\Event\MessageEvent) perdrait son effet ; il
     * n'en existe aucune aujourd'hui.
     */
    private function deliveryFailure(Throwable $exception): AccountInvitationDeliveryException
    {
        // Sous les enveloppes des bus : un échec du transport garde sa classe
        // et son code (issue #427).
        $cause = MailerSendingFailure::innermost($exception);
        if ($cause instanceof TransportExceptionInterface) {
            // La classe et le code seulement.
            $failure = MailerTransportFailure::from($cause);

            return AccountInvitationDeliveryException::becauseTransportFailed($failure->exceptionClass, $failure->code);
        }

        return AccountInvitationDeliveryException::becauseSendingFailed(MailerSendingFailure::describe($exception));
    }

    private function invitationEmail(
        CpgUser $user,
        string $recipientEmail,
        Locale $locale,
        #[SensitiveParameter]
        string $clearToken,
    ): TemplatedEmail {
        // Le jeton voyage dans le FRAGMENT du lien, jamais dans son chemin
        // (audit A7, décision D6) : un navigateur n'envoie pas le fragment au
        // serveur, il n'atteint donc ni les access logs du nginx frontend ni
        // ceux de l'ingress. La page le lit côté client, puis l'efface de
        // l'URL (cf. SetPasswordPage.vue).
        $setupUrl = \sprintf(
            '%s/%s/set-password#%s',
            rtrim($this->frontendBaseUrl, '/'),
            $locale->value,
            $clearToken,
        );

        return (new TemplatedEmail())
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
