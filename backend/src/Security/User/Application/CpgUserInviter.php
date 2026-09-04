<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
    ) {
    }

    public function invite(string $email, Locale $locale): CpgUser
    {
        if (null !== $this->cpgUserRepository->findOneByEmail($email)) {
            throw EmailAlreadyUsedException::forEmail($email);
        }

        // Mot de passe vide : le compte n'est utilisable qu'une fois le mot de
        // passe défini via le lien d'invitation (cf. PasswordSetupService).
        $user = new CpgUser($this->usernameGenerator->generateFromEmail($email), '');
        $user->setEmail($email);
        $user->markInvited($this->clock->now());

        try {
            $this->cpgUserRepository->save($user);
        } catch (UniqueConstraintViolationException) {
            // findOneByEmail() ci-dessus n'est pas atomique avec le save() : sur
            // deux invitations concurrentes de la même adresse (ou deux parties
            // locales identiques), la contrainte unique en base reste le dernier
            // rempart — même parti pris que CpgUserRegistrar::register(). Le
            // message n'est publié qu'après un save() réussi : sur échec, rien.
            throw EmailAlreadyUsedException::forEmail($email);
        }

        $this->dispatchInvitation($user, $locale);

        return $user;
    }

    public function reinvite(CpgUser $user, Locale $locale): void
    {
        if (!$user->isPendingActivation()) {
            throw AccountNotAwaitingActivationException::forUsername($user->getUsername());
        }

        $this->dispatchInvitation($user, $locale);
    }

    /**
     * Le handler régénère le jeton (invalidant le précédent) : redispatcher le
     * message suffit à « renvoyer » l'invitation.
     */
    private function dispatchInvitation(CpgUser $user, Locale $locale): void
    {
        $userId = $user->getId();
        // save() (invite) a affecté l'identifiant généré ; reinvite() reçoit un
        // compte déjà persisté. Non-null dans les deux cas.
        \assert(null !== $userId);

        $this->messageBus->dispatch(new SendAccountInvitationMessage($userId, $locale->value));
    }
}
