<?php

declare(strict_types=1);

namespace App\Security\User\Application;

use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Security\User\Application\Message\SendAccountInvitationMessage;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Domain\Entity\PasswordSetupToken;
use App\Security\User\Domain\Exception\AccountNotAwaitingActivationException;
use App\Security\User\Domain\Exception\EmailAlreadyUsedException;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Domain\Repository\PasswordSetupTokenRepositoryInterface;
use App\Security\User\Domain\Service\UsernameGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class CpgUserInviter implements CpgUserInviterInterface
{
    /** Durée de validité du jeton de définition de mot de passe. */
    private const string TOKEN_LIFETIME = '+48 hours';

    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
        private UsernameGenerator $usernameGenerator,
        private PasswordSetupTokenRepositoryInterface $passwordSetupTokenRepository,
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

        $this->issueTokenAndDispatch($user, $email, $locale);

        return $user;
    }

    public function reinvite(CpgUser $user, Locale $locale): void
    {
        if (!$user->isPendingActivation()) {
            throw AccountNotAwaitingActivationException::forUsername($user->getUsername());
        }

        $email = $user->getEmail();
        // isPendingActivation() implique invitedAt non null, donc un e-mail posé
        // par invite() : l'assertion l'explicite pour l'analyse statique.
        \assert(null !== $email);

        $this->issueTokenAndDispatch($user, $email, $locale);
    }

    /**
     * (Re)pose la date d'invitation, régénère l'unique jeton de définition de
     * mot de passe et redispatch l'e-mail d'invitation.
     */
    private function issueTokenAndDispatch(CpgUser $user, string $email, Locale $locale): void
    {
        $now = $this->clock->now();

        $user->markInvited($now);
        $this->cpgUserRepository->save($user);

        $clearToken = bin2hex(random_bytes(32));
        $this->passwordSetupTokenRepository->deleteForUser($user);
        $this->passwordSetupTokenRepository->save(new PasswordSetupToken(
            $user,
            hash('sha256', $clearToken),
            $now->modify(self::TOKEN_LIFETIME),
        ));

        $this->messageBus->dispatch(new SendAccountInvitationMessage(
            recipientEmail: $email,
            username: $user->getUsername(),
            clearToken: $clearToken,
            locale: $locale->value,
        ));
    }
}
