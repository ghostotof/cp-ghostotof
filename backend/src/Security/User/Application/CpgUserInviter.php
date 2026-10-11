<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Exception\InvitationQueueUnavailableException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cas d'usage "inviter un utilisateur depuis le backoffice". Depuis le point
 * d'audit C2 (décision D3), ce service se limite à créer / marquer le compte
 * en attente d'activation puis à publier SendAccountInvitationMessage : la
 * création du PasswordSetupToken et l'envoi de l'e-mail vivent désormais dans
 * SendAccountInvitationHandler, pour qu'aucun secret ne transite par Messenger.
 */
final readonly class CpgUserInviter implements CpgUserInviterInterface
{
    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private UsernameGenerator $usernameGenerator,
        private MessageBusInterface $messageBus,
        private ClockInterface $clock,
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    public function invite(string $email, Locale $locale): CpgUser
    {
        if (null !== $this->cpgUserRepository->findOneByEmail($email)) {
            throw EmailAlreadyUsedException::alreadyLinkedToAnAccount();
        }

        // Mot de passe vide : le compte n'est utilisable qu'une fois le mot de
        // passe défini via le lien d'invitation (cf. PasswordSetupService).
        $user = new CpgUser($this->usernameGenerator->generateFromEmail($email), '');
        $user->setEmail($email);
        // ADR 0003 D1 : inviter depuis le backoffice *est* l'octroi nominatif
        // de ROLE_TRUSTED par un ROLE_SUPER — ce n'est jamais un compte au
        // seul palier de base.
        $user->setRoles([CpgUser::ROLE_TRUSTED]);
        $user->markInvited($this->clock->now());

        try {
            $this->cpgUserRepository->save($user);
        } catch (UniqueConstraintViolationException) {
            // findOneByEmail() ci-dessus n'est pas atomique avec le save() : sur
            // deux invitations concurrentes de la même adresse (ou deux parties
            // locales identiques), la contrainte unique en base reste le dernier
            // rempart — même parti pris que CpgUserRegistrar::register(). Le
            // message n'est publié qu'après un save() réussi : sur échec, rien.
            throw EmailAlreadyUsedException::alreadyLinkedToAnAccount();
        }

        try {
            $this->dispatchInvitation($user, $locale);
        } catch (InvitationQueueUnavailableException $unavailable) {
            // Issue #485 : la file n'a pas pris le message. Le compte tout juste
            // créé est retiré, pour que l'administrateur réessaie sans buter
            // sur « adresse déjà utilisée ». Si le broker l'avait reçu malgré
            // tout (accusé perdu, #479), le handler trouve un compte absent,
            // journalise un warning et s'arrête sans réessai.
            $this->cpgUserRepository->remove($user);

            throw $unavailable;
        }
        // Journal de sécurité (D5) : après le save() et le dispatch réussis.
        // L'auteur (le ROLE_SUPER qui invite) est lu par le logger dans le
        // jeton de sécurité de la requête, le compte est nommé par son
        // identifiant et son id — jamais par l'e-mail.
        $this->auditLogger->userInvited($user);

        return $user;
    }

    public function reinvite(CpgUser $user, Locale $locale): void
    {
        if (!$user->isPendingActivation()) {
            throw AccountNotAwaitingActivationException::forUsername($user->getUsername());
        }

        // Round de correction (C1, issue #238) : la relance *est* une nouvelle
        // invitation au sens de la purge automatique — le délai de 30 jours
        // doit repartir de zéro, pas continuer de courir depuis le tout
        // premier envoi. Même ordre que invite() : on écrit avant de
        // dispatcher. La génération est avancée en base, atomiquement (issue
        // #435) : sur un double clic, deux requêtes chargent la même
        // génération, et l'avancer en mémoire publiait deux messages de même
        // génération, que le handler ne savait plus départager.
        $now = $this->clock->now();
        $user->markReinvited($now, $this->cpgUserRepository->advanceInvitationGeneration($user, $now));

        $this->dispatchInvitation($user, $locale);
        $this->auditLogger->userReinvited($user);
    }

    /**
     * Le handler régénère le jeton (invalidant le précédent) : redispatcher le
     * message suffit à « renvoyer » l'invitation.
     *
     * Le message porte la génération que markInvited() vient d'avancer
     * (issue #434) : un message plus ancien encore en file, réessai ou rejeu,
     * saura qu'une invitation plus récente l'a remplacé.
     */
    private function dispatchInvitation(CpgUser $user, Locale $locale): void
    {
        // L'identité est posée par le constructeur de l'entité (spec 0003 D1) :
        // elle existe avant même le save(), et voyage en chaîne RFC 4122 (D7).
        try {
            $this->messageBus->dispatch(
                new SendAccountInvitationMessage($user->getId()->toRfc4122(), $locale->value, $user->getInvitationGeneration()),
            );
        } catch (TransportException $failure) {
            // Broker arrêté, refus ou accusé manquant (#479) : un 503 nommé
            // (issue #485), sans audit — l'invitation n'est pas partie. Une
            // relance garde sa génération avancée en base (limite écrite).
            throw new InvitationQueueUnavailableException($failure);
        }
    }
}
