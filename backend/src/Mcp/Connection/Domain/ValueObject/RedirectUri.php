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
 * stricte comparerait deux écritures d'une même URI. Une redirection demandée
 * aussi, à la casse ASCII du schéma et de l'hôte près (revue de #647) : le
 * serveur redirige vers la chaîne **demandée**, telle quelle, et non vers la
 * forme normalisée qui a été comparée — `http://ｌｏｃａｌｈｏｓｔ:5/cb` (pleine
 * chasse), `http://%6Cocalhost:5/cb` ou `…/c b` se normalisent en une boucle
 * locale enregistrée, mais ce n'est pas elles qu'un navigateur ou un client
 * suivrait à coup sûr.
 */
final readonly class RedirectUri implements Stringable
{
    /** Les seuls hôtes admis en `http`, tels que League\Uri les rend. */
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** Le plus grand port TCP ; League\Uri lit `:0` et `:99999` sans broncher. */
    private const int MAX_PORT = 65535;

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

        if (self::carriesIss($uri)) {
            throw InvalidRedirectUriException::withIssParameter();
        }

        if (!self::hasPortInRange($uri)) {
            throw InvalidRedirectUriException::portOutOfRange();
        }

        return new self($value, $uri);
    }

    /**
     * La requête porte-t-elle un `iss` ? Le serveur ajoute le sien (RFC 9207)
     * à toute redirection vers le client : une redirection qui en porterait
     * déjà un en aurait deux, et le client lirait peut-être le mauvais. Lu
     * comme PHP le lit (`parse_str()` : `%69ss`, `iss[]` comptent aussi).
     */
    private static function carriesIss(Uri $uri): bool
    {
        $query = $uri->getQuery();

        if (null === $query) {
            return false;
        }

        parse_str($query, $parameters);

        return \array_key_exists('iss', $parameters);
    }

    /**
     * Vrai si la redirection demandée par le client désigne celle-ci. Une
     * demande illisible, pas écrite sous sa forme normalisée (hors casse du
     * schéma et de l'hôte ; un port vide, `localhost:/`, est donc refusé), qui
     * porte un fragment ou des identifiants (même vides : `#`, `@`) ou un port
     * hors de 1 à 65535 n'en désigne aucune — jamais d'exception ici, le point
     * d'autorisation répond par un refus.
     */
    public function matches(string $requested): bool
    {
        $candidate = Uri::parse($requested);

        if (null === $candidate
            || !$this->isNormalizedButForCase($candidate, $requested)
            || null !== $candidate->getFragment()
            || null !== $candidate->getUserInfo()
            || !self::hasPortInRange($candidate)) {
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

    /**
     * La chaîne demandée est-elle la forme que League\Uri en rend, à la casse
     * ASCII près ? League\Uri ne change la casse que du schéma et de l'hôte
     * (RFC 3986 §6.2.2.1 : insensibles à la casse) ; chemin, requête et
     * encodages `%xx` gardent la leur. Toute autre réécriture — encodage d'un
     * caractère, hôte en pleine chasse ou `%`-encodé ramené à l'ASCII, port vide
     * ou `:05` retiré — change autre chose que la casse, et la demande est
     * refusée. `strtolower()` ne touche que l'ASCII.
     */
    private function isNormalizedButForCase(Uri $candidate, string $requested): bool
    {
        return strtolower($candidate->toString()) === strtolower($requested);
    }

    /** Aucun port, ou un port TCP utilisable : 1 à 65535. */
    private static function hasPortInRange(Uri $uri): bool
    {
        $port = $uri->getPort();

        return null === $port || ($port >= 1 && $port <= self::MAX_PORT);
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
