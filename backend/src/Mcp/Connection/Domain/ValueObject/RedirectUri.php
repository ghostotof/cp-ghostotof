<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidRedirectUriException;
use League\Uri\Uri;
use Stringable;

/**
 * Une redirection enregistrée d'un client MCP (#647, spec 0007 phase 2), et la
 * règle qui juge une redirection demandée contre elle.
 *
 * Deux formes seulement, celles d'OAuth 2.1 pour un client public :
 * - `https`, comparée en **égalité stricte de la chaîne** (OAuth 2.1 §2.3.1) :
 *   ni port, ni casse, ni barre finale de tolérance ;
 * - `http` sur la **boucle locale** — `localhost`, `127.0.0.1`, `[::1]`, rien
 *   d'autre —, comparée **sans le port** (RFC 8252 §7.3) : un client natif
 *   (Claude Code, un IDE) ouvre un port éphémère qu'il ne peut pas annoncer à
 *   l'enregistrement. Schéma et hôte sans casse (RFC 3986 §6.2.2.1), chemin et
 *   requête à l'identique.
 * Les schémas privés (`com.example.app:/…`, RFC 8252 §7.1) sont hors périmètre :
 * aucun client visé n'en a besoin, et leur propriétaire n'est vérifié par
 * personne.
 *
 * League\Uri est l'analyseur de `league/oauth2-server` : la redirection est lue
 * comme le serveur d'autorisation la lira. Une redirection enregistrée doit
 * être écrite sous la forme que League\Uri en rend, sans quoi l'égalité
 * stricte comparerait deux écritures d'une même URI.
 */
final readonly class RedirectUri implements Stringable
{
    /** Les seuls hôtes admis en `http`, tels que League\Uri les rend. */
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    private function __construct(
        private string $value,
        private Uri $uri,
    ) {
    }

    /**
     * @throws InvalidRedirectUriException
     */
    public static function fromString(string $value): self
    {
        $uri = Uri::parse($value) ?? throw InvalidRedirectUriException::unparsable();

        if ($uri->toString() !== $value) {
            throw InvalidRedirectUriException::notNormalized();
        }

        $scheme = $uri->getScheme();

        if ('https' !== $scheme && 'http' !== $scheme) {
            throw InvalidRedirectUriException::unsupportedScheme();
        }

        $host = $uri->getHost();

        if (null === $host || '' === $host) {
            throw InvalidRedirectUriException::withoutHost();
        }

        if ('http' === $scheme && !\in_array($host, self::LOOPBACK_HOSTS, true)) {
            throw InvalidRedirectUriException::plainHttpOutsideLoopback();
        }

        if (null !== $uri->getUserInfo()) {
            throw InvalidRedirectUriException::withUserInfo();
        }

        if (null !== $uri->getFragment()) {
            throw InvalidRedirectUriException::withFragment();
        }

        return new self($value, $uri);
    }

    /**
     * Vrai si la redirection demandée par le client désigne celle-ci. Une
     * demande illisible, ou qui porte un fragment ou des identifiants (même
     * vides : `#`, `@`), n'en désigne aucune — jamais d'exception ici, le point
     * d'autorisation répond par un refus.
     */
    public function matches(string $requested): bool
    {
        $candidate = Uri::parse($requested);

        if (null === $candidate || null !== $candidate->getFragment() || null !== $candidate->getUserInfo()) {
            return false;
        }

        if (!$this->isLoopback()) {
            return $requested === $this->value;
        }

        // League\Uri rend schéma et hôte en minuscules : la comparaison des
        // deux écritures sans port est celle de RFC 3986 pour ces parties, et
        // l'égalité pour le chemin et la requête.
        return $candidate->withPort(null)->toString() === $this->uri->withPort(null)->toString();
    }

    /** Une redirection `http` : la boucle locale, seule admise sans TLS. */
    public function isLoopback(): bool
    {
        return 'http' === $this->uri->getScheme();
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
