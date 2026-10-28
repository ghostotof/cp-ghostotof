<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Security\AccountReference;
use App\Shared\Domain\Security\McpConnectionReference;
use App\Shared\Infrastructure\Http\CanonicalPath;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

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
 *
 * Un compte n'y est jamais nommé par son identifiant de connexion, ni comme
 * sujet ni comme auteur (issue #363) : pour un invité, cet identifiant est
 * dérivé de son adresse (ADR 0001 D2), souvent un prénom et un nom. Un compte
 * se désigne par son `userId` ; le marqueur `guest-…` du palier de base,
 * aléatoire, est le seul identifiant écrit tel quel (`user`).
 */
#[WithMonologChannel('security_audit')]
final readonly class SecurityAuditLogger implements SecurityAuditLoggerInterface
{
    private const string ANONYMOUS = 'anonymous';

    /** Acteur d'un événement déclenché par une commande CLI planifiée, jamais par une personne. */
    private const string SYSTEM = 'system';

    /**
     * Cause de révocation d'une connexion MCP expirée (#649), le mot de
     * `RevocationCause::Expired` : écrit ici, ce contexte ne dépend pas de `Mcp`.
     */
    private const string EXPIRED = 'expired';

    /**
     * Cause de révocation d'une connexion MCP remplacée par un nouvel accord
     * (#726), le mot de `RevocationCause::Superseded` : écrit ici, ce contexte
     * ne dépend pas de `Mcp`.
     */
    private const string SUPERSEDED = 'superseded';

    /** Acteur d'un événement déclenché à la main en ligne de commande, par une personne que rien n'identifie. */
    private const string CONSOLE = 'console';

    /**
     * Acteur authentifié qui n'est ni un compte ni le sujet du palier de base :
     * aucun fournisseur n'en produit aujourd'hui. Son identifiant n'est pas
     * écrit, faute de savoir ce qu'il contient.
     */
    private const string UNIDENTIFIED = 'unidentified';

    public function __construct(
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private TokenStorageInterface $tokenStorage,
        private LoginIdentifierFingerprint $fingerprint,
    ) {
    }

    public function loginSucceeded(UserInterface $user): void
    {
        $this->record('login-succeeded', 'Login succeeded.', $this->principal($user));
    }

    public function loginFailed(#[SensitiveParameter] ?string $triedIdentifier, ?CpgUser $account): void
    {
        $this->record('login-failed', 'Login failed.', $this->attemptSubject($triedIdentifier, $account));
    }

    public function loginThrottled(#[SensitiveParameter] ?string $triedIdentifier): void
    {
        $this->record('login-throttled', 'Login refused by throttling.', $this->attemptSubject($triedIdentifier, null));
    }

    public function loggedOut(?UserInterface $user): void
    {
        $this->record('logged-out', 'Logged out.', $this->principal($user));
    }

    public function baseAccessIssued(string $baseAccessMarker): void
    {
        $this->record('base-access-issued', 'Base-tier token issued.', ['user' => $baseAccessMarker]);
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
            [...$this->account($user), 'superAdmin' => $user->isSuperAdmin()],
            self::CONSOLE,
        );
    }

    public function mcpConnectionGranted(McpConnectionReference $connection): void
    {
        $this->record('mcp-connection-granted', 'MCP connection granted.', $this->connection($connection));
    }

    public function nominativeCorpusRead(McpConnectionReference $connection, string $tool): void
    {
        $this->record('nominative-corpus-read', 'Nominative corpus read.', [...$this->connection($connection), 'tool' => $tool]);
    }

    public function mcpConnectionRevoked(McpConnectionReference $connection, string $cause): void
    {
        $this->record('mcp-connection-revoked', 'MCP connection revoked.', [...$this->connection($connection), 'cause' => $cause]);
    }

    public function mcpConnectionSuperseded(McpConnectionReference $revoked, Uuid $supersededBy): void
    {
        $this->record('mcp-connection-revoked', 'MCP connection revoked.', [
            ...$this->connection($revoked),
            'cause' => self::SUPERSEDED,
            'supersededBy' => $supersededBy->toRfc4122(),
        ]);
    }

    public function mcpConnectionExpired(McpConnectionReference $connection): void
    {
        // Acteur forcé à `system`, comme userPurged() : l'expiration naît de
        // la purge planifiée, hors de toute requête HTTP.
        $this->record(
            'mcp-connection-revoked',
            'MCP connection revoked.',
            [...$this->connection($connection), 'cause' => self::EXPIRED],
            self::SYSTEM,
        );
    }

    public function mcpAccessTokenRefused(McpConnectionReference $connection, string $cause): void
    {
        $this->record('mcp-access-token-refused', 'MCP access token refused.', [...$this->connection($connection), 'cause' => $cause]);
    }

    public function mcpGrantRefused(McpConnectionReference $connection, string $grantType, string $cause, string $presentingClientId): void
    {
        $this->record('mcp-grant-refused', 'MCP grant refused.', [
            ...$this->connection($connection),
            'cause' => $cause,
            'grantType' => $grantType,
            'presentingClientId' => $presentingClientId,
        ]);
    }

    public function sessionRefusedAfterCredentialsChange(CpgUser $account, ?int $presentedVersion): void
    {
        $this->record('session-refused-after-credentials-change', 'Session refused: credentials changed since it was opened.', [
            ...$this->account($account),
            'presentedVersion' => $presentedVersion,
            'currentVersion' => $account->getCredentialsVersion(),
        ]);
    }

    /**
     * Un compte se désigne par son UUID seul (issue #363) : ni son adresse
     * e-mail, ni son identifiant de connexion, qui pour un invité en est
     * dérivé (`jean.dupont@…` → `jean.dupont`, ADR 0001 D2). C'est une
     * minimisation (RGPD art. 5-1-c), pas l'objectif n°9, qui porte sur ce que
     * l'API expose. Seule la base relie l'UUID à une personne, tant que le
     * compte existe : une fois supprimé, ses lignes ne désignent plus personne
     * (registre RGPD, §6). Pour nommer un compte pendant un incident :
     * `SELECT username, email FROM cpg_user WHERE id = '<userId>'`.
     *
     * @return array{userId: string}
     */
    private function account(AccountReference $user): array
    {
        return ['userId' => $user->accountId()];
    }

    /**
     * Le sujet de tout événement d'une connexion MCP (#647, #648, #649) : le
     * compte, la connexion, le client par son identifiant public — dans cet
     * ordre, le même pour chacun ; l'appelant y ajoute sa cause ou son outil.
     *
     * @return array{userId: string, connectionId: string, clientId: string}
     */
    private function connection(McpConnectionReference $connection): array
    {
        return [
            ...$this->account($connection),
            'connectionId' => $connection->connectionId()->toRfc4122(),
            'clientId' => $connection->clientId(),
        ];
    }

    /**
     * Le sujet d'un utilisateur authentifié, tel que {@see PrincipalDesignation}
     * le désigne : `userId` pour un compte, `user` pour le marqueur `guest-…`,
     * rien d'autre.
     *
     * @return array<string, string>
     */
    private function principal(?UserInterface $user): array
    {
        $designation = null !== $user ? PrincipalDesignation::of($user) : null;

        return null !== $designation ? [$designation->key => $designation->value] : [];
    }

    /** L'auteur de la requête courante, désigné comme un sujet ({@see self::principal()}). */
    private function currentActor(): string
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (null === $user) {
            return self::ANONYMOUS;
        }

        return PrincipalDesignation::of($user)->value ?? self::UNIDENTIFIED;
    }

    /**
     * Le sujet d'une tentative de login (issue #418). L'identifiant saisi ne
     * sort jamais : un mot de passe tapé dans le champ identifiant partirait
     * sinon en clair dans les journaux de production. Il est remplacé par son
     * empreinte, qui suffit à corréler les tentatives — entre elles et avec le
     * compteur de `login_throttling`. Le compte, quand l'authentification l'a
     * chargé, est désigné par son UUID (issue #363), jamais par la saisie ni
     * par son identifiant.
     *
     * @return array{userId?: string, userFingerprint: string|null}
     */
    private function attemptSubject(#[SensitiveParameter] ?string $triedIdentifier, ?CpgUser $account): array
    {
        $fingerprint = null !== $triedIdentifier ? $this->fingerprint->of($triedIdentifier) : null;

        if (null === $account) {
            return ['userFingerprint' => $fingerprint];
        }

        return [...$this->account($account), 'userFingerprint' => $fingerprint];
    }

    /**
     * @param array<string, bool|int|string|null> $subject ce que l'événement vise, clés choisies par l'appelant
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
            'actor' => $actor ?? $this->currentActor(),
            'ip' => $request?->getClientIp(),
            // Décodé (issue #77) : la forme que le routeur et le firewall ont vue.
            // Jamais réécrit : aucune route ne porte de secret dans son chemin
            // (audit A7, D6 — le jeton d'activation voyage dans le corps).
            'path' => null !== $request ? CanonicalPath::of($request) : null,
        ]);
    }
}
