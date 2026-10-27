<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidConnectionLifetimeException;
use DateInterval;
use DateMalformedIntervalStringException;
use DateTimeImmutable;

/**
 * Les durées d'une connexion MCP et de ses jetons (#649) : code
 * d'autorisation, jeton d'accès, jeton de rafraîchissement, délai
 * d'inactivité et durée maximale de la connexion.
 *
 * Lues en configuration (paramètres `app.mcp.lifetime.*` de
 * `config/services.yaml`), **une seule valeur pour tous les environnements**,
 * sans `%env()%` : `SecurityServicesEnvironmentParityTest` compare toute
 * définition `App\` d'un environnement à l'autre (#659), et une durée de jeton
 * propre à la production échapperait à tous les tests.
 *
 * Chaque durée est un intervalle ISO 8601 construit (`new DateInterval(…)`),
 * jamais une expression relative : les jours se comptent sur le calendrier du
 * fuseau de l'instant, les heures en temps écoulé (#445, #710). Trente jours
 * à Paris durent donc 721 heures à travers le passage à l'heure d'hiver.
 *
 * **L'échéance d'une connexion se définit par deux échéances fixes**,
 * {@see self::idleDeadline()} et {@see self::maximumDeadline()} : le dernier
 * usage (ou l'octroi) plus le délai d'inactivité, l'octroi plus la durée
 * maximale ({@see \App\Mcp\Connection\Domain\Entity\McpConnection::isExpiredAt()}).
 * Une échéance ne dépend que de la connexion, jamais de l'instant jugé : une
 * fois atteinte, la connexion reste morte. Des seuils (l'instant jugé moins la
 * durée) ne le garantissaient pas : pendant l'heure répétée du passage à
 * l'heure d'hiver, `$now->sub(P30D)` recule d'une heure quand `$now` avance,
 * et une connexion expirée à 02:45 CEST revivait à 02:15 CET (relecture de la
 * tâche 1 de #649).
 *
 * Un objet valeur immuable ; `DateInterval` étant mutable, chaque lecture en
 * rend une copie.
 */
final readonly class ConnectionLifetimes
{
    /**
     * Plancher du délai d'inactivité et de la durée maximale d'une connexion,
     * et marge de la présélection de la purge (relecture du lot B de #649) :
     * la purge retient en SQL les connexions dont le dernier usage (ou
     * l'octroi) précède « maintenant − durée + cette marge », puis juge chacune
     * par {@see \App\Mcp\Connection\Domain\Entity\McpConnection::isExpiredAt()} —
     * la borne SQL et l'échéance ne se répondent qu'à l'heure près autour d'un
     * changement d'heure, un jour les couvre. Une durée plus courte que la
     * marge mettrait la borne dans le futur : chaque connexion active serait
     * verrouillée `FOR UPDATE` à chaque nuit. Une seule constante pour les
     * deux, que la purge lit ici.
     */
    public const string MINIMUM_CONNECTION_LIFETIME = 'P1D';

    /**
     * L'instant fixe où les durées se comparent, pour que le verdict ne
     * dépende pas du jour où le conteneur se construit (un mois de 28 jours
     * contre 30 jours, par exemple) : positivité, plancher et ordres jugés
     * sur le même.
     */
    private const string REFERENCE_INSTANT = '2026-01-01T00:00:00+00:00';

    private function __construct(
        private DateInterval $authorizationCode,
        private DateInterval $accessToken,
        private DateInterval $refreshToken,
        private DateInterval $idle,
        private DateInterval $maximum,
    ) {
    }

    /**
     * Chaque paramètre se nomme `…Lifetime` : une durée, que le message d'un
     * refus désigne par ce nom.
     *
     * @throws InvalidConnectionLifetimeException si une durée est illisible ou
     *         nulle, si l'inactivité ou la durée maximale est sous un jour
     *         (la marge de présélection de la purge), si l'inactivité dépasse
     *         la durée maximale, ou si le jeton d'accès survit à son jeton de
     *         rafraîchissement
     */
    public static function fromConfiguration(string $codeLifetime, string $accessLifetime, string $refreshLifetime, string $idleLifetime, string $maximumLifetime): self
    {
        $durations = [
            'codeLifetime' => self::read('codeLifetime', $codeLifetime),
            'accessLifetime' => self::read('accessLifetime', $accessLifetime),
            'refreshLifetime' => self::read('refreshLifetime', $refreshLifetime),
            'idleLifetime' => self::read('idleLifetime', $idleLifetime),
            'maximumLifetime' => self::read('maximumLifetime', $maximumLifetime),
        ];

        $reference = new DateTimeImmutable(self::REFERENCE_INSTANT);

        foreach ($durations as $parameter => $duration) {
            if ($reference->add($duration) <= $reference) {
                throw InvalidConnectionLifetimeException::notPositive($parameter);
            }
        }

        // Le plancher avant l'ordre des deux durées : une durée maximale sous
        // un jour est refusée pour cette cause, pas parce que l'inactivité la
        // dépasse.
        $oneDay = $reference->add(new DateInterval(self::MINIMUM_CONNECTION_LIFETIME));
        foreach (['idleLifetime', 'maximumLifetime'] as $parameter) {
            if ($reference->add($durations[$parameter]) < $oneDay) {
                throw InvalidConnectionLifetimeException::belowOneDay($parameter);
            }
        }

        if ($reference->add($durations['idleLifetime']) > $reference->add($durations['maximumLifetime'])) {
            throw InvalidConnectionLifetimeException::idleExceedsMaximum();
        }

        if ($reference->add($durations['accessLifetime']) > $reference->add($durations['refreshLifetime'])) {
            throw InvalidConnectionLifetimeException::accessTokenOutlivesRefreshToken();
        }

        return new self(
            $durations['codeLifetime'],
            $durations['accessLifetime'],
            $durations['refreshLifetime'],
            $durations['idleLifetime'],
            $durations['maximumLifetime'],
        );
    }

    /** Durée d'un code d'autorisation, de son émission à son échange. */
    public function authorizationCode(): DateInterval
    {
        return clone $this->authorizationCode;
    }

    /** Durée d'un jeton d'accès (`expires_in` de la réponse du point de jeton). */
    public function accessToken(): DateInterval
    {
        return clone $this->accessToken;
    }

    /** Durée d'un jeton de rafraîchissement, renouvelé à chaque usage (rotation). */
    public function refreshToken(): DateInterval
    {
        return clone $this->refreshToken;
    }

    /** Délai d'inactivité : une connexion inutilisée aussi longtemps expire. */
    public function idle(): DateInterval
    {
        return clone $this->idle;
    }

    /** Durée maximale : passé ce délai après l'octroi, la connexion expire quel que soit son usage. */
    public function maximum(): DateInterval
    {
        return clone $this->maximum;
    }

    /**
     * L'instant où une connexion inactive depuis `$lastActivity` (son dernier
     * usage, ou son octroi) expire : atteint, elle est morte.
     *
     * Les jours se comptent sur le calendrier du fuseau de `$lastActivity`
     * (McpConnection::isExpiredAt() l'y ramène à celui de l'instant jugé). Une
     * heure murale qui n'existe pas à l'échéance (02:30 le jour du passage à
     * l'heure d'été) est reportée par PHP d'une heure plus tard : la connexion
     * vit alors une heure de plus, jamais de moins, et l'échéance reste fixe.
     */
    public function idleDeadline(DateTimeImmutable $lastActivity): DateTimeImmutable
    {
        return $lastActivity->add($this->idle);
    }

    /**
     * L'instant où une connexion octroyée à `$grantedAt` expire quel que soit
     * son usage : atteint, elle est morte. Même calendrier qu'idleDeadline().
     */
    public function maximumDeadline(DateTimeImmutable $grantedAt): DateTimeImmutable
    {
        return $grantedAt->add($this->maximum);
    }

    /**
     * Lit une durée ISO 8601, ou la refuse en nommant son paramètre.
     *
     * L'exception de PHP n'est pas chaînée : son message cite la valeur.
     *
     * @throws InvalidConnectionLifetimeException si la durée est illisible
     */
    private static function read(string $parameter, string $duration): DateInterval
    {
        try {
            return new DateInterval($duration);
        } catch (DateMalformedIntervalStringException) {
            throw InvalidConnectionLifetimeException::unreadable($parameter);
        }
    }
}
