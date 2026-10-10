<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
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

    /** Acteur d'un événement déclenché par une commande CLI planifiée, jamais par une personne. */
    private const string SYSTEM = 'system';

    /** Acteur d'un événement déclenché à la main en ligne de commande, par une personne que rien n'identifie. */
    private const string CONSOLE = 'console';

    public function __construct(
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
        private LoginIdentifierFingerprint $fingerprint,
    ) {
    }

    public function loginSucceeded(string $username): void
    {
        $this->record('login-succeeded', 'Login succeeded.', ['user' => $username]);
    }

    public function loginFailed(#[SensitiveParameter] ?string $triedIdentifier, ?CpgUser $account): void
    {
        $this->record('login-failed', 'Login failed.', $this->attemptSubject($triedIdentifier, $account));
    }

    public function loginThrottled(#[SensitiveParameter] ?string $triedIdentifier): void
    {
        $this->record('login-throttled', 'Login refused by throttling.', $this->attemptSubject($triedIdentifier, null));
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

    public function rateLimiterUnavailable(): void
    {
        $this->record('rate-limiter-unavailable', 'Request refused: rate limiter lock unavailable.');
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

    public function passwordSetupTokenRejected(): void
    {
        $this->record('password-setup-token-rejected', 'Password-setup token rejected: unknown token.');
    }

    public function passwordSetupTokenReplayed(CpgUser $user): void
    {
        $this->record('password-setup-token-replayed', 'Password-setup token replayed: link already used.', $this->account($user));
    }

    public function passwordSetupThrottled(): void
    {
        $this->record('password-setup-throttled', 'Password-setup request refused by its rate limiter.');
    }

    public function contactThrottled(): void
    {
        $this->record('contact-throttled', 'Contact message refused by its rate limiter.');
    }

    public function baseAccessThrottled(): void
    {
        $this->record('base-access-throttled', 'Base-tier token refused by its rate limiter.');
    }

    public function userPurged(CpgUser $user): void
    {
        // Acteur forcé à `system` : cet événement naît d'une commande CLI
        // planifiée, hors de toute requête HTTP — le jeton de sécurité
        // courant n'existe pas, et le lire donnerait `anonymous`, qui
        // suggérerait à tort une action humaine non identifiée.
        $this->record(
            'user-purged',
            'Compte en attente d\'activation purgé (invitation expirée).',
            [...$this->account($user), 'reason' => 'invitation-expired'],
            self::SYSTEM,
        );
    }

    public function userCreated(CpgUser $user): void
    {
        // Acteur forcé à `console` : hors requête, le jeton de sécurité
        // n'existe pas, et `anonymous` suggérerait un appelant HTTP.
        $this->record(
            'user-created',
            'User created from the command line.',
            [...$this->account($user), 'superAdmin' => \in_array(CpgUser::ROLE_SUPER, $user->getRoles(), true)],
            self::CONSOLE,
        );
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
     * Le sujet d'une tentative de login (issue #418). L'identifiant saisi ne
     * sort jamais : un mot de passe tapé dans le champ identifiant partirait
     * sinon en clair dans les journaux de production. Il est remplacé par son
     * empreinte, qui suffit à corréler les tentatives — entre elles et avec le
     * compteur de `login_throttling`. Le nom en clair ne vient que d'un compte
     * chargé : c'est alors `getUsername()` qui est écrit, pas la saisie.
     *
     * `user` reste présent (à `null`) sans compte, comme avant : les lectures
     * `jq '.context.user // "-"'` du runbook (k8s/README.md) ne changent pas.
     *
     * @return array{user: string|null, userId?: string, userFingerprint: string|null}
     */
    private function attemptSubject(#[SensitiveParameter] ?string $triedIdentifier, ?CpgUser $account): array
    {
        $fingerprint = null !== $triedIdentifier ? $this->fingerprint->of($triedIdentifier) : null;

        if (null === $account) {
            return ['user' => null, 'userFingerprint' => $fingerprint];
        }

        return [...$this->account($account), 'userFingerprint' => $fingerprint];
    }

    /**
     * @param array<string, bool|string|null> $subject ce que l'événement vise, clés choisies par l'appelant
     * @param string|null                     $actor   acteur imposé par l'appelant (`system` pour une
     *                                                 commande planifiée, `console` pour une commande
     *                                                 lancée à la main) ; sinon lu dans le jeton de sécurité de
     *                                                 la requête courante, `anonymous` à défaut de jeton
     */
    private function record(string $event, string $message, array $subject = [], ?string $actor = null): void
    {
        $request = $this->requestStack->getMainRequest();

        $this->logger->info($message, [
            'event' => $event,
            ...$subject,
            'actor' => $actor ?? $this->tokenStorage->getToken()?->getUserIdentifier() ?? self::ANONYMOUS,
            'ip' => $request?->getClientIp(),
            // Décodé (issue #77) : la forme que le routeur et le firewall ont vue.
            // Jamais réécrit : aucune route ne porte de secret dans son chemin
            // (audit A7, D6 — le jeton d'activation voyage dans le corps).
            'path' => null !== $request ? CanonicalPath::of($request) : null,
        ]);
    }
}
