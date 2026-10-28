<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Entity;

use App\Mcp\Connection\Domain\ValueObject\ConnectionLifetimes;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Mcp\Connection\Infrastructure\Doctrine\McpConnectionRepository;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Security\McpConnectionReference;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Connexion MCP (glossaire, `CONTEXT.md`) : l'autorisation qu'une personne du
 * palier de confiance accorde à un client MCP de lire le corpus nominatif en
 * son nom. Ses codes et jetons s'y rattachent ; ils n'en sont que le support
 * technique.
 *
 * Le compte et le client sont des clés étrangères en cascade : un compte
 * supprimé n'a plus de connexion (ni, par cascade, de code ou de jeton), un
 * client retiré n'en laisse aucune derrière lui. Le dernier usage est écrit au
 * rafraîchissement (#649, {@see self::recordUseAt()}), au plus une fois par
 * heure écoulée, jamais à chaque appel.
 *
 * **Une connexion se révoque par le dépôt seulement** (#649,
 * {@see \App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface::revokeActive()}) :
 * ce modèle n'a aucun mutateur de `revokedAt` ni de `revocationCause`. La
 * révocation est une instruction SQL, `UPDATE … WHERE revoked_at IS NULL` : la
 * base fait de la première la seule (instant et cause gardés), sans lecture
 * préalable qu'une révocation concurrente rendrait fausse, et elle tient
 * après une transaction annulée qui a fermé l'EntityManager — c'est là que
 * league constate un rafraîchissement réutilisé ou un code rejoué, et l'unité
 * de travail n'y écrit plus rien. Une instance déjà chargée n'est pas
 * rafraîchie. La base refuse un instant sans cause et une cause sans instant
 * (`chk_mcp_connection_revocation_cause`).
 *
 * **Révoquer une connexion ne touche pas les lignes de ses codes et jetons**
 * (décision 6 de #649) : tout lecteur juge la connexion
 * ({@see self::isRevoked()} — l'authentificateur de `/api/mcp`, les dépôts de
 * league), et `revoked_at` d'un code ou d'un jeton garde son seul sens,
 * l'usage unique d'un code et la rotation d'un rafraîchissement — c'est ce qui
 * permet de reconnaître la réutilisation d'un rafraîchissement tourné. Une
 * seule écriture par révocation, quel que soit le nombre de jetons.
 *
 * L'index partiel `idx_mcp_connection_active_pair` sert la recherche de
 * l'accord actif d'un couple compte/client, qu'un nouvel accord remplace
 * (cause `superseded`).
 *
 * Un McpConnectionReference : le journal de sécurité la reçoit telle quelle
 * (l'accord, le refus d'un de ses jetons d'accès), son compte par son UUID,
 * son client par son identifiant public — lus sur le compte et le client
 * qu'elle tient déjà, sans requête de plus quand ils ont été joints.
 */
#[ORM\Entity(repositoryClass: McpConnectionRepository::class)]
#[ORM\Table(name: 'mcp_connection')]
#[ORM\Index(name: 'idx_mcp_connection_active_pair', columns: ['account_id', 'mcp_client_id'], options: ['where' => '(revoked_at IS NULL)'])]
class McpConnection implements McpConnectionReference
{
    /**
     * Le seul scope qu'une connexion accorde (spec 0007 phase 2) : lire le
     * corpus nominatif au nom de la personne qui a consenti. Écrit ici, dans
     * le domaine ; le serveur d'autorisation le relit (NominativeReadScope).
     */
    public const string SCOPE_NOMINATIVE_READ = 'nominative:read';

    /**
     * Le dernier usage ne se réécrit qu'une fois cette durée **écoulée**
     * depuis le précédent (#649, {@see self::recordUseAt()}) : une heure, en
     * temps écoulé — jamais une heure de pendule, qui vaut zéro ou deux
     * pendant l'heure répétée du passage à l'heure d'hiver (#445).
     */
    public const string USAGE_WRITE_INTERVAL = 'PT1H';

    /** Spec 0003 : UUID v7 posé par le constructeur. */
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CpgUser::class)]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'CASCADE')]
    private CpgUser $account;

    /**
     * `mcp_client_id`, pas `client_id` : ce dernier nom est, dans `mcp_client`,
     * l'identifiant public d'OAuth (un texte), et ici une clé UUID.
     */
    #[ORM\ManyToOne(targetEntity: McpClient::class)]
    #[ORM\JoinColumn(name: 'mcp_client_id', nullable: false, onDelete: 'CASCADE')]
    private McpClient $client;

    #[ORM\Column(length: 255)]
    private string $scope;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $grantedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    /**
     * L'instant où le code de l'octroi a été échangé pour la première fois
     * (#730), `null` tant qu'il ne l'a pas été : la connexion d'une approbation
     * abandonnée, dont le code n'est jamais échangé, expire tôt
     * ({@see self::deadline()}). Écrit au premier échange par
     * {@see self::recordCodeExchangeAt()}, jamais ensuite.
     */
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $codeExchangedAt = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    /** Écrite avec `revokedAt`, par le dépôt seulement (voir la classe). */
    #[ORM\Column(length: 32, nullable: true, enumType: RevocationCause::class)]
    private ?RevocationCause $revocationCause = null;

    private function __construct(CpgUser $account, McpClient $client, string $scope, DateTimeImmutable $grantedAt)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->client = $client;
        $this->scope = $scope;
        $this->grantedAt = $grantedAt;
    }

    /** Le consentement de `$account` à `$client`, pour `$scope`, à l'instant `$at`. */
    public static function grant(CpgUser $account, McpClient $client, string $scope, DateTimeImmutable $at): self
    {
        return new self($account, $client, $scope, $at);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function account(): CpgUser
    {
        return $this->account;
    }

    public function client(): McpClient
    {
        return $this->client;
    }

    /** L'UUID du compte qui a consenti, forme RFC 4122 : celui de {@see self::account()}. */
    public function accountId(): string
    {
        return $this->account->accountId();
    }

    /** L'UUID de la connexion : {@see self::getId()}, sous le nom que le journal de sécurité lit. */
    public function connectionId(): Uuid
    {
        return $this->id;
    }

    /** L'identifiant public OAuth de son client (`mcp_client.client_id`). */
    public function clientId(): string
    {
        return $this->client->getClientId();
    }

    public function scope(): string
    {
        return $this->scope;
    }

    /**
     * La connexion accorde-t-elle `nominative:read` ? Le scope stocké est la
     * liste que league a retenue, séparée par une espace (RFC 6749 §3.3) :
     * comparé mot pour mot et à la casse près (un scope est sensible à la
     * casse), jamais par préfixe.
     */
    public function grantsNominativeRead(): bool
    {
        return \in_array(self::SCOPE_NOMINATIVE_READ, explode(' ', $this->scope), true);
    }

    public function grantedAt(): DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function lastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    /**
     * Note l'usage de la connexion à l'instant `$at` — au rafraîchissement
     * seulement, jamais à chaque appel de `/api/mcp` (ADR 0006 D6) — et dit
     * si l'instant a été écrit : il l'est quand aucun usage n'est encore noté,
     * ou quand {@see self::USAGE_WRITE_INTERVAL} s'est écoulé depuis le
     * précédent ; sinon le précédent reste, et rien n'est à sauver. C'est ce
     * qui borne les écritures d'un client qui rafraîchirait sans cesse à une
     * par heure, au prix d'un délai d'inactivité compté depuis un usage vieux
     * d'au plus une heure.
     *
     * L'intervalle est construit (`new DateInterval`), ajouté en temps écoulé,
     * et les instants se comparent entre eux, quel que soit leur fuseau : un
     * usage lu en base porte un décalage fixe, l'instant jugé celui de Paris.
     */
    public function recordUseAt(DateTimeImmutable $at): bool
    {
        if (null !== $this->lastUsedAt && $this->lastUsedAt->add(new DateInterval(self::USAGE_WRITE_INTERVAL)) > $at) {
            return false;
        }

        $this->lastUsedAt = $at;

        return true;
    }

    /** L'instant du premier échange du code de l'octroi, ou `null` s'il n'a jamais eu lieu (#730). */
    public function codeExchangedAt(): ?DateTimeImmutable
    {
        return $this->codeExchangedAt;
    }

    /**
     * Note le premier échange du code de l'octroi à l'instant `$at` (#730) :
     * la connexion cesse d'être soumise à l'échéance des connexions jamais
     * échangées. **Écrit une fois** : un appel suivant ne déplace pas l'instant
     * (un code ne s'échange qu'une fois ; un rejeu est une compromission,
     * jugée ailleurs). Appelé par le remplacement au premier échange
     * ({@see \App\Mcp\Connection\Application\CodeExchangeSupersession}), sur
     * l'instance que league a verrouillée, dans la transaction de l'échange.
     */
    public function recordCodeExchangeAt(DateTimeImmutable $at): void
    {
        $this->codeExchangedAt ??= $at;
    }

    public function revokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    /** Pourquoi la connexion a été révoquée ; `null` tant qu'elle ne l'est pas. */
    public function revocationCause(): ?RevocationCause
    {
        return $this->revocationCause;
    }

    /**
     * L'instant où la connexion expire : **la plus proche de ses échéances**,
     * dans le fuseau `$zone` (#650). **La seule définition de l'échéance**
     * (#649) — celle que {@see self::isExpiredAt()} juge et que « Mes
     * connexions MCP » affiche, jamais deux calculs qui pourraient se séparer :
     *
     * - l'inactivité : {@see ConnectionLifetimes::idleDeadline()} de
     *   `lastUsedAt ?? grantedAt` ;
     * - la durée maximale : {@see ConnectionLifetimes::maximumDeadline()} de
     *   `grantedAt` ;
     * - **tant que son code n'a pas été échangé** (#730) :
     *   {@see ConnectionLifetimes::unexchangedDeadline()} de `grantedAt`, une
     *   heure — l'approbation abandonnée n'est ni remplacée ni révoquée par
     *   personne, aucun usage ne la tient.
     *
     * Des échéances, jamais des seuils (`$now` moins la durée) : une échéance
     * ne dépend que de la connexion, l'expiration est donc monotone — morte à
     * un instant, morte à tout instant suivant. Un seuil reculait d'une heure
     * pendant l'heure répétée du passage à l'heure d'hiver, et la connexion
     * revivait (relecture de la tâche 1). La purge (#649) présélectionne ses
     * candidates en SQL puis juge chacune par {@see self::isExpiredAt()}.
     *
     * Les jours se comptent sur le calendrier de `$zone` (721 heures pour 30
     * jours à Paris à travers le passage à l'heure d'hiver), jamais par
     * `modify()` (#445) : les instants y sont ramenés avant l'ajout, car
     * Doctrine hydrate une colonne `datetimetz_immutable` avec un décalage
     * fixe (`+02:00`), sur lequel 30 jours dureraient 720 heures — une connexion
     * lue en base n'expirerait pas à la même seconde qu'une connexion en mémoire.
     * L'horloge rend toujours le même fuseau : l'échéance reste fixe.
     *
     * `lastUsedAt` n'est écrit qu'au rafraîchissement, par
     * {@see self::recordUseAt()} — jamais à chaque appel (ADR 0006 D6).
     */
    public function deadline(DateTimeZone $zone, ConnectionLifetimes $lifetimes): DateTimeImmutable
    {
        $lastActivity = ($this->lastUsedAt ?? $this->grantedAt)->setTimezone($zone);
        $grantedAt = $this->grantedAt->setTimezone($zone);
        $deadline = min($lifetimes->idleDeadline($lastActivity), $lifetimes->maximumDeadline($grantedAt));

        return null === $this->codeExchangedAt
            ? min($deadline, $lifetimes->unexchangedDeadline($grantedAt))
            : $deadline;
    }

    /**
     * La connexion a-t-elle expiré à l'instant `$now` ? Exclusif : atteinte à
     * la seconde de {@see self::deadline()}, la connexion est morte. Les
     * échéances se comptent dans le fuseau de `$now`. La révocation n'est pas
     * une expiration : {@see self::isRevoked()} se lit à part.
     */
    public function isExpiredAt(DateTimeImmutable $now, ConnectionLifetimes $lifetimes): bool
    {
        return $now >= $this->deadline($now->getTimezone(), $lifetimes);
    }
}
