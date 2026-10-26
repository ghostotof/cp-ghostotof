<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidConnectionLifetimeException;
use DateInterval;
use DateMalformedIntervalStringException;
use DateTimeImmutable;
use SensitiveParameter;

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
 * **L'échéance d'une connexion se définit par deux seuils**,
 * {@see self::idleThreshold()} et {@see self::maximumThreshold()} : l'instant
 * jugé moins la durée. La connexion est morte quand son dernier usage (ou son
 * octroi) est au plus tard le premier, ou son octroi au plus tard le second
 * ({@see \App\Mcp\Connection\Domain\Entity\McpConnection::isExpiredAt()}). La
 * purge les relit tels quels en SQL : une seule définition, la même seconde.
 *
 * Un objet valeur immuable ; `DateInterval` étant mutable, chaque lecture en
 * rend une copie.
 */
final readonly class ConnectionLifetimes
{
    /**
     * L'instant fixe où les durées se comparent, pour que le verdict ne
     * dépende pas du jour où le conteneur se construit (un mois de 28 jours
     * contre 30 jours, par exemple) : positivité et ordres jugés sur le même.
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
     * `#[SensitiveParameter]` sur les trois durées de jeton : ce ne sont pas
     * des secrets, mais leur nom dit « token » ou « authorization », que
     * `SecretParametersTest` (#414) exige masqué sans liste d'exemption — et
     * masquer une durée dans une trace ne coûte rien.
     *
     * @throws InvalidConnectionLifetimeException si une durée est illisible ou
     *         nulle, si l'inactivité dépasse la durée maximale, ou si le jeton
     *         d'accès survit à son jeton de rafraîchissement
     */
    public static function fromConfiguration(
        #[SensitiveParameter] string $authorizationCode,
        #[SensitiveParameter] string $accessToken,
        #[SensitiveParameter] string $refreshToken,
        string $idle,
        string $maximum,
    ): self {
        $durations = [
            'authorizationCode' => self::read('authorizationCode', $authorizationCode),
            'accessToken' => self::read('accessToken', $accessToken),
            'refreshToken' => self::read('refreshToken', $refreshToken),
            'idle' => self::read('idle', $idle),
            'maximum' => self::read('maximum', $maximum),
        ];

        $reference = new DateTimeImmutable(self::REFERENCE_INSTANT);

        foreach ($durations as $parameter => $duration) {
            if ($reference->add($duration) <= $reference) {
                throw InvalidConnectionLifetimeException::notPositive($parameter);
            }
        }

        if ($reference->add($durations['idle']) > $reference->add($durations['maximum'])) {
            throw InvalidConnectionLifetimeException::idleExceedsMaximum();
        }

        if ($reference->add($durations['accessToken']) > $reference->add($durations['refreshToken'])) {
            throw InvalidConnectionLifetimeException::accessTokenOutlivesRefreshToken();
        }

        return new self(...$durations);
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
     * L'instant à partir duquel un dernier usage est trop ancien à `$now` :
     * un dernier usage (ou un octroi, à défaut) **au plus tard** égal à ce
     * seuil fait expirer la connexion.
     */
    public function idleThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub($this->idle);
    }

    /**
     * L'instant à partir duquel un octroi est trop ancien à `$now` : un octroi
     * **au plus tard** égal à ce seuil fait expirer la connexion.
     */
    public function maximumThreshold(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub($this->maximum);
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
