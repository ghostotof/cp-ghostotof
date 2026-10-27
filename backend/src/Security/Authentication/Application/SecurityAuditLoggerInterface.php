<?php

declare(strict_types=1);

namespace App\Security\Authentication\Application;

use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Security\McpConnectionReference;
use SensitiveParameter;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Journal de sécurité (D5, constat A5 du 3e audit) : l'unique point d'entrée
 * par lequel l'application dit « ceci vient de se produire, et voici qui l'a
 * fait ». Une méthode par événement, pour que la liste de ce qui est
 * journalisé se lise d'un coup ici plutôt qu'en grep sur `logger->info`.
 *
 * Qui appelle quoi :
 *  - les événements Symfony (login réussi/raté/throttlé, logout) et le 403
 *    backoffice : Infrastructure\Log\SecurityEventsSubscriber ;
 *  - les deux gardes CSRF : juste avant de lever leur exception ;
 *  - la panne du verrou des limiteurs (issue #276) :
 *    Infrastructure\Http\RateLimiterLockFailureListener ;
 *  - l'émission d'un jeton du palier de base : BaseAccessController ;
 *  - les actions d'administration et l'activation d'un compte : les cas
 *    d'usage de Security\User\Application, après l'action réussie ;
 *  - les refus du parcours de définition de mot de passe qu'aucune autre
 *    trace n'attribue (issue #356) : PasswordSetupService, avant de lever ;
 *  - les quotas anonymes atteints (issue #356) :
 *    Infrastructure\Log\ThrottledRequestAuditListener ;
 *  - la purge automatique des invitations jamais activées (issue #238) :
 *    PendingInvitationPurger, une par compte supprimé ;
 *  - la création d'un compte en ligne de commande (issue #386) :
 *    CpgUserRegistrar, après l'enregistrement ;
 *  - une connexion MCP accordée (#647) : ConsentService, après le COMMIT de
 *    la connexion et de son code ;
 *  - le corpus nominatif lu par une connexion MCP (#648) :
 *    {@see \App\Mcp\Infrastructure\Corpus\NominativeCorpusReader}, pour une
 *    réponse que l'outil `get_nominative_corpus` sert, après sa mesure ;
 *  - une connexion MCP révoquée ou expirée (#649), `mcp-connection-revoked`,
 *    une ligne par connexion, seulement pour une révocation effective :
 *    {@see \App\Mcp\Connection\Application\McpConnectionRevoker} (après la
 *    révocation : réutilisation d'un rafraîchissement tourné, rejeu d'un
 *    code), {@see \App\Mcp\Connection\Infrastructure\League\LeagueRevocationEndpoint}
 *    (révocation par le client), {@see \App\Mcp\Connection\Application\ConsentService}
 *    (accord remplacé) et {@see \App\Mcp\Connection\Application\McpConnectionPurger}
 *    (expiration, acteur `system`), ces trois-là après le COMMIT ;
 *  - un jeton d'accès MCP connu refusé (#649), `mcp-access-token-refused` :
 *    {@see \App\Mcp\Connection\Infrastructure\Security\McpBearerAuthenticator},
 *    avant de lever le refus, jamais pour un jeton mal formé ou inconnu.
 *
 * Ce qui sort, et rien d'autre : `event` (kebab-case, stable — c'est la clé
 * sur laquelle on filtre), le sujet (`userId`, l'UUID d'un compte ; `user`,
 * le seul marqueur `guest-…` du palier de base ; pour un login raté ou
 * refusé, l'empreinte de l'identifiant tenté, `userFingerprint`, issue
 * #418), l'auteur (`actor` : l'UUID du compte authentifié, le marqueur
 * `guest-…`, `anonymous`, `system` ou `console`), l'IP et le chemin. Jamais un
 * mot de passe, un jeton (JWT, XSRF, invitation), un e-mail, l'identifiant
 * de connexion d'un compte (issue #363 : pour un invité, il est dérivé de son
 * adresse), un corps de requête ni une exception sérialisée — un journal de
 * sécurité qui contient un secret est lui-même une fuite.
 * SecurityAuditLoggerTest pince cette règle avec des valeurs sentinelles,
 * AccountIdentifierReachesNoLogTest sur tous les canaux.
 *
 * L'auteur n'est pas un paramètre : c'est l'implémentation qui le lit dans le
 * jeton de sécurité de la requête courante. Un cas d'usage n'a ainsi rien à
 * savoir de qui l'appelle pour laisser une trace exacte.
 */
interface SecurityAuditLoggerInterface
{
    /** Login réussi sur le firewall `login` : le compte par son UUID. */
    public function loginSucceeded(UserInterface $user): void;

    /**
     * Échec de login. L'identifiant tenté n'est **jamais** écrit tel quel
     * (issue #418) : ce peut être un mot de passe tapé dans le mauvais champ.
     * La ligne porte son empreinte (`userFingerprint`), et désigne le compte
     * (`userId`) seulement quand l'identifiant en désigne un.
     *
     * @param string|null  $triedIdentifier identifiant saisi, s'il a pu être lu
     * @param CpgUser|null $account         le compte qu'il désigne, déjà chargé par l'authentification ;
     *                                      `null` s'il n'en désigne aucun ou s'il n'a pas été chargé
     */
    public function loginFailed(#[SensitiveParameter] ?string $triedIdentifier, ?CpgUser $account): void;

    /**
     * Refus par login_throttling : le mot de passe n'a pas été vérifié, ni le
     * compte chargé — et il ne doit pas l'être, ce serait une requête SQL sur
     * le chemin que l'attaquant martèle. D'où l'absence de compte dans la
     * signature : la ligne porte l'empreinte seule, calculée
     * comme la clé du compteur, qui relie le refus aux échecs qui l'ont causé
     * quand c'est le compteur par (IP, identifiant) qui a refusé. Le compteur
     * par IP seule (25 essais en 15 min) peut refuser une saisie jamais vue :
     * l'empreinte ne se relie alors à rien, l'`ip` de la ligne, si.
     *
     * @param string|null $triedIdentifier identifiant saisi, s'il a pu être lu
     */
    public function loginThrottled(#[SensitiveParameter] ?string $triedIdentifier): void;

    /** Déconnexion : un compte par son UUID, le palier de base par son marqueur `guest-…`. */
    public function loggedOut(?UserInterface $user): void;

    /** ADR 0003 D6 : jeton ROLE_USER émis sans compte, identifié par son marqueur `guest-…`. */
    public function baseAccessIssued(string $baseAccessMarker): void;

    /** Rejet par l'un des deux gardes CSRF ; le chemin dit lequel. */
    public function csrfRejected(): void;

    /** 403 sur `/api/backoffice` : un appelant authentifié sans ROLE_SUPER. */
    public function backofficeAccessDenied(): void;

    /**
     * Requête refusée en 503 parce que le verrou partagé d'un limiteur de
     * débit n'a pu être pris (ADR 0005 D8/D10, issue #276). Sans sujet : la
     * ressource verrouillée contient la clé du limiteur (IP, identifiant
     * tenté), le chemin suffit à dire lequel a cédé. Distinct de
     * `login-failed` : aucun identifiant n'a été vérifié.
     */
    public function rateLimiterUnavailable(): void;

    public function userInvited(CpgUser $user): void;

    public function userReinvited(CpgUser $user): void;

    /** @param bool $superAdmin ROLE_SUPER accordé (`true`) ou retiré (`false`) */
    public function roleChanged(CpgUser $user, bool $superAdmin): void;

    public function passwordChanged(CpgUser $user): void;

    public function userDeleted(CpgUser $user): void;

    /** Fin du parcours d'invitation : mot de passe défini, compte utilisable. */
    public function accountActivated(CpgUser $user): void;

    /**
     * Jeton de définition de mot de passe inconnu (404, issue #356). Sans
     * sujet : ni le jeton soumis (un secret, ou presque — une faute de frappe
     * sur un vrai lien), ni un compte (aucun ne correspond). L'IP et le
     * chemin attribuent un essai de jetons à la chaîne.
     */
    public function passwordSetupTokenRejected(): void;

    /**
     * Lien déjà consommé présenté de nouveau (issue #356) : signe possible
     * d'une fuite du lien. Côté journal seulement — la réponse est celle de
     * tout lien inutilisable (404, issue #362), qui ne dit pas à l'appelant
     * que le lien a servi. Désigne le compte que le lien activait, jamais le jeton.
     */
    public function passwordSetupTokenReplayed(CpgUser $user): void;

    /**
     * Quotas par IP des routes anonymes atteints (429, issue #356), un
     * événement par route. Sans sujet : la clé du limiteur est l'IP, déjà
     * portée par la ligne. Les quotas par compte, ceux du traducteur et de
     * l'assistant de carrière, sont sur `ai_usage` avec le compte (ADR 0004).
     */
    public function passwordSetupThrottled(): void;

    public function contactThrottled(): void;

    public function baseAccessThrottled(): void;

    /**
     * Compte en attente d'activation supprimé par la purge automatique
     * (PendingInvitationPurger, issue #238) faute d'activation dans le délai.
     * Déclenché par une commande CLI planifiée, jamais par une action
     * humaine : l'acteur journalisé est `system`, jamais le jeton de sécurité
     * de la requête courante (il n'y en a pas).
     */
    public function userPurged(CpgUser $user): void;

    /**
     * Compte créé par `app:user:create` (CpgUserRegistrar, issue #386), seule
     * voie qui en crée un sans invitation — et celle du premier ROLE_SUPER.
     * Porte `superAdmin`, comme `role-changed`. L'acteur journalisé est
     * `console` : une personne au terminal, que le journal ne sait pas
     * nommer, distincte de `system` (un traitement planifié).
     */
    public function userCreated(CpgUser $user): void;

    /**
     * Connexion MCP accordée par une personne du palier de confiance (#647,
     * ADR 0006 D3) : un client lira le corpus nominatif en son nom. Désigne le
     * compte (`userId`), la connexion (`connectionId`, l'UUID que la
     * révocation et la liste « Mes connexions MCP » désignent aussi) et le
     * client par son identifiant public (`clientId`, le `client_id` d'OAuth
     * d'un client public : aucun secret), tous trois lus sur la connexion
     * accordée. Jamais le code d'autorisation émis, ni un jeton, ni leur
     * empreinte.
     */
    public function mcpConnectionGranted(McpConnectionReference $connection): void;

    /**
     * Corpus nominatif lu par une connexion MCP (#648, ADR 0006 D5) : l'outil
     * l'a rendu au client, qui le passe au modèle de son fournisseur. Désigne
     * le compte dont la connexion a été authentifiée (`userId` — c'est aussi
     * l'auteur, lu dans le jeton de sécurité), la connexion (`connectionId`),
     * le client par son identifiant public (`clientId`) et l'outil (`tool`).
     * Reçoit un McpConnectionReference et non un CpgUser : le lecteur du
     * corpus, hors de ce contexte, ne connaît le compte que par l'identité du
     * firewall `mcp`.
     * Jamais le jeton présenté, son empreinte, ni rien du corpus.
     */
    public function nominativeCorpusRead(McpConnectionReference $connection, string $tool): void;

    /**
     * Connexion MCP révoquée (#649) : désigne le compte (`userId`), la
     * connexion (`connectionId`), le client par son identifiant public
     * (`clientId`) et la cause (`cause`, le mot stable de
     * {@see \App\Mcp\Connection\Domain\ValueObject\RevocationCause} :
     * `client`, `refresh-token-reuse`, `code-replay`, `superseded`). Une
     * chaîne plutôt que l'enum : ce contexte ne dépend pas de `Mcp`. L'auteur
     * est lu comme pour tout événement. Jamais le jeton ou le code présenté,
     * ni leur empreinte.
     */
    public function mcpConnectionRevoked(McpConnectionReference $connection, string $cause): void;

    /**
     * Connexion MCP expirée, constatée et révoquée par la purge planifiée
     * (#649) : le même événement `mcp-connection-revoked`, cause `expired`.
     * L'acteur journalisé est `system`, comme pour {@see self::userPurged()} :
     * une commande planifiée n'a pas de jeton de sécurité, et `anonymous`
     * suggérerait un appelant humain.
     */
    public function mcpConnectionExpired(McpConnectionReference $connection): void;

    /**
     * Jeton d'accès MCP connu, présenté sur `/api/mcp` et refusé (#649) :
     * désigne le compte de sa connexion (`userId`), la connexion
     * (`connectionId`), le client par son identifiant public (`clientId`) et
     * la cause (`cause`, le mot stable de
     * {@see \App\Mcp\Connection\Domain\ValueObject\BearerRefusal} : jeton
     * révoqué ou échu, autre audience, connexion révoquée ou expirée, scope,
     * compte hors palier de confiance). Une chaîne plutôt que l'enum : ce
     * contexte ne dépend pas de `Mcp`. Jamais pour un Bearer mal formé ni un
     * jeton inconnu, qui ne désignent aucune connexion. L'auteur est lu comme
     * pour tout événement (`anonymous` : le refus précède toute
     * authentification). Jamais le jeton présenté ni son empreinte.
     */
    public function mcpAccessTokenRefused(McpConnectionReference $connection, string $cause): void;

    /**
     * Code d'autorisation ou jeton de rafraîchissement authentique — que le
     * serveur a émis — refusé au point de jeton (#725) : désigne le compte de
     * sa connexion (`userId`), la connexion (`connectionId`), le client de la
     * connexion par son identifiant public (`clientId`), la cause (`cause`,
     * le mot stable de {@see \App\Mcp\Connection\Domain\ValueObject\GrantRefusal}),
     * le grant (`grantType`, `authorization_code` ou `refresh_token`) et le
     * client qui l'a présenté (`presentingClientId`, identifiant public du
     * client enregistré nommé dans le corps de la requête) — c'est ce dernier
     * qui rend lisible un code présenté par un autre client. Des chaînes
     * plutôt que les enums : ce contexte ne dépend pas de `Mcp` ; deux
     * chaînes voisines, que l'appelant passe par leur nom.
     * Jamais pour un artefact illisible, un client inconnu ni une connexion
     * disparue. L'auteur est lu comme pour tout événement (`anonymous` : le
     * point de jeton n'authentifie personne). Jamais l'artefact présenté, le
     * vérificateur PKCE ni une empreinte.
     */
    public function mcpGrantRefused(McpConnectionReference $connection, string $grantType, string $cause, string $presentingClientId): void;
}
