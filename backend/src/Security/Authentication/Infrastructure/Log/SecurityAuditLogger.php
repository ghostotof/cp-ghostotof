<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Implémentation Monolog du journal de sécurité (D5), sur le canal
 * `security_audit` : en prod, une ligne JSON sur stderr par appel, `info`,
 * jamais bufferisée (config/packages/monolog.yaml). Ce que chaque ligne porte,
 * et surtout ce qu'elle ne porte pas, est dit sur l'interface.
 *
 * Le contexte est construit ici et seulement ici (`record()`), à partir de
 * valeurs choisies une à une — jamais d'un tableau reçu, d'une entité
 * sérialisée ni d'une exception : c'est ce qui rend la règle « pas de
 * secret » vérifiable par un test plutôt que par une relecture.
 *
 * L'IP et le chemin viennent de la requête principale courante ; hors requête
 * (commande, handler Messenger), ils sont `null` et l'événement sort quand
 * même. L'auteur est lu dans le jeton de sécurité : un événement journalisé
 * avant le firewall (rejet CSRF, priorité 20 contre 8) a donc pour auteur
 * `anonymous` même si un cookie BEARER accompagnait la requête — ce qui est
 * exact, la requête a été refusée avant toute authentification.
 */
#[WithMonologChannel('security_audit')]
final readonly class SecurityAuditLogger implements SecurityAuditLoggerInterface
{
    private const string ANONYMOUS = 'anonymous';

    /**
     * Le jeton de définition de mot de passe voyage encore dans le chemin de
     * l'URL (constat A7 ; son déplacement dans le corps est la Task 4.1, D6).
     * Le chemin d'une activation est donc un secret : le segment est remplacé
     * par `{token}` pour que la route reste lisible sans que le jeton y soit.
     * À retirer avec T4.1, quand plus aucune route ne portera de jeton.
     */
    private const string TOKEN_BEARING_PATH_PATTERN = '#^(/api/account/password-setup/)[^/]+$#';

    public function __construct(
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function loginSucceeded(string $username): void
    {
        $this->record('login-succeeded', 'Login succeeded.', ['user' => $username]);
    }

    public function loginFailed(?string $username): void
    {
        $this->record('login-failed', 'Login failed.', ['user' => $username]);
    }

    public function loginThrottled(?string $username): void
    {
        $this->record('login-throttled', 'Login refused by throttling.', ['user' => $username]);
    }

    public function loggedOut(?string $username): void
    {
        $this->record('logged-out', 'Logged out.', ['user' => $username]);
    }

    public function baseAccessIssued(string $guestIdentifier): void
    {
        $this->record('base-access-issued', 'Base-tier token issued.', ['user' => $guestIdentifier]);
    }

    public function csrfRejected(): void
    {
        $this->record('csrf-rejected', 'Request rejected by a CSRF guard.');
    }

    public function backofficeAccessDenied(): void
    {
        $this->record('backoffice-access-denied', 'Backoffice access denied.');
    }

    public function userInvited(CpgUser $user): void
    {
        $this->record('user-invited', 'User invited.', $this->account($user));
    }

    public function userReinvited(CpgUser $user): void
    {
        $this->record('user-reinvited', 'Invitation sent again.', $this->account($user));
    }

    public function roleChanged(CpgUser $user, bool $superAdmin): void
    {
        $this->record('role-changed', 'Super-admin role changed.', [...$this->account($user), 'superAdmin' => $superAdmin]);
    }

    public function passwordChanged(CpgUser $user): void
    {
        $this->record('password-changed', 'Password changed by an administrator.', $this->account($user));
    }

    public function userDeleted(CpgUser $user): void
    {
        $this->record('user-deleted', 'User deleted.', $this->account($user));
    }

    public function accountActivated(CpgUser $user): void
    {
        $this->record('account-activated', 'Account activated.', $this->account($user));
    }

    /**
     * Un compte se nomme par son identifiant de connexion et son id : jamais
     * par son e-mail, donnée personnelle qui n'a rien à faire dans un journal
     * (Goal #9 : rien d'identifiant en dehors du palier nominatif).
     *
     * @return array{user: string, userId: string}
     */
    private function account(CpgUser $user): array
    {
        return ['user' => $user->getUsername(), 'userId' => $user->getId()->toRfc4122()];
    }

    /**
     * @param array<string, bool|string|null> $subject ce que l'événement vise, clés choisies par l'appelant
     */
    private function record(string $event, string $message, array $subject = []): void
    {
        $request = $this->requestStack->getMainRequest();

        $this->logger->info($message, [
            'event' => $event,
            ...$subject,
            'actor' => $this->tokenStorage->getToken()?->getUserIdentifier() ?? self::ANONYMOUS,
            'ip' => $request?->getClientIp(),
            'path' => null !== $request ? $this->loggablePath($request) : null,
        ]);
    }

    /**
     * Décodé (issue #77) — la forme que le routeur et le firewall ont vue —,
     * puis débarrassé du seul segment qui soit un secret.
     */
    private function loggablePath(Request $request): string
    {
        return (string) preg_replace(self::TOKEN_BEARING_PATH_PATTERN, '$1{token}', CanonicalPath::of($request));
    }
}
