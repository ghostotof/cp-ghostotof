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
use App\Security\User\Domain\Exception\UsernameAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use DomainException;
use Psr\Clock\ClockInterface;
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
        private UsernameAllocationLockInterface $usernameAllocationLock,
    ) {
    }

    public function invite(string $email, Locale $locale): CpgUser
    {
        // Issue #364 : vérifications et enregistrement sous un même verrou.
        // Le message n'est publié qu'après la validation de la transaction —
        // publié avant, le handler pourrait chercher un compte encore
        // invisible. Sur un refus, rien n'est publié.
        $user = $this->usernameAllocationLock->whileAllocating(fn (): CpgUser => $this->createPendingAccount($email));

        try {
            $this->dispatchInvitation($user, $locale);
        } catch (InvitationQueueUnavailableException $unavailable) {
            // Issue #485 : la file n'a pas pris le message. Le compte tout juste
            // créé est retiré, pour que l'administrateur réessaie sans buter
            // sur « adresse déjà utilisée ». Si le broker l'avait reçu malgré
            // tout (accusé perdu, #479), le handler trouve un compte absent,
            // journalise un warning et s'arrête sans réessai ; s'il l'avait
            // traité avant ce retrait, son lien est mort (jeton parti avec le
            // compte) et l'administrateur renvoie une invitation.
            $this->cpgUserRepository->removePendingInvitation($user);

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
     * Crée et enregistre le compte en attente d'activation. Appelée sous le
     * verrou d'attribution : les deux lectures d'unicité voient toute
     * invitation validée avant elle (issue #364).
     *
     * @throws EmailAlreadyUsedException    si l'adresse est déjà rattachée à un compte
     * @throws UsernameAlreadyUsedException si l'identifiant dérivé a été pris hors du verrou
     */
    private function createPendingAccount(string $email): CpgUser
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
        } catch (UniqueConstraintViolationException $violation) {
            // Sous le verrou, deux invitations ne se croisent plus. Reste une
            // écriture qui ne le prend pas (création CLI, saisie à la main en
            // base) : la contrainte unique est alors le dernier rempart, même
            // parti pris que CpgUserRegistrar::register().
            throw $this->refusalFor($violation, $user);
        }

        return $user;
    }

    /**
     * Nomme l'unicité qui a refusé l'enregistrement (issue #364) : `cpg_user`
     * en porte deux, et dire « adresse déjà utilisée » pour un identifiant
     * pris envoyait l'administrateur chercher un compte qui n'existe pas.
     * PostgreSQL cite l'index violé dans son message, quelle que soit la
     * langue de ses messages (`lc_messages`) : le nom n'est jamais traduit.
     *
     * Aucune des exceptions rendues ne garde la violation pour `previous` :
     * le détail de PostgreSQL cite la valeur en conflit, donc l'adresse
     * (issue #356). Une violation qui ne nomme aucun des deux index (index
     * renommé sans ce code) remonte telle quelle, en 500 : mieux qu'un 409
     * qui affirmerait une cause non vérifiée.
     *
     * Une ligne qui prendrait à la fois l'adresse et l'identifiant ne fait
     * citer qu'un index, le premier que PostgreSQL vérifie. Sous le verrou,
     * la vérification de l'adresse l'a déjà écartée ; hors verrou, c'est la
     * limite écrite dans UsernameAllocationLockInterface.
     */
    private function refusalFor(UniqueConstraintViolationException $violation, CpgUser $user): DomainException|UniqueConstraintViolationException
    {
        $message = $violation->getMessage();

        return match (true) {
            str_contains($message, CpgUser::EMAIL_UNIQUE_INDEX) => EmailAlreadyUsedException::alreadyLinkedToAnAccount(),
            str_contains($message, CpgUser::USERNAME_UNIQUE_INDEX) => UsernameAlreadyUsedException::forUsername($user->getUsername()),
            default => $violation,
        };
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
        // Broker arrêté, refus ou accusé manquant (#479) : le bus lève
        // l'exception que nomme le message, InvitationQueueUnavailableException
        // (issues #485 et #491), sans audit — l'invitation n'est pas partie.
        // Une relance garde sa génération avancée en base (limite écrite).
        $this->messageBus->dispatch(
            new SendAccountInvitationMessage($user->getId()->toRfc4122(), $locale->value, $user->getInvitationGeneration()),
        );
    }
}
