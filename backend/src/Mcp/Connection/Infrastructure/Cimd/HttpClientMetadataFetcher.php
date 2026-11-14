<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetcherInterface;
use App\Mcp\Connection\Application\Cimd\FetchedClientMetadata;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Demande à l'hôte d'un `client_id` CIMD le document qu'il publie (#651,
 * plan de la tâche 12, D-D). L'hôte vient d'un tiers : tout ce qui suit sert
 * à ce que la requête n'atteigne jamais le réseau du serveur.
 *
 * Les cinq couches contre le SSRF suivent la numérotation de D-D (plan de la
 * tâche 12), la même dans toute la classe, `services.yaml` et les tests.
 *
 * Couche 1. L'hôte doit figurer dans la liste des domaines admis
 *    ({@see AllowedClientDomains}) : sinon il est refusé
 *    ({@see ClientMetadataRefusal::DomainNotAllowed}) avant toute résolution,
 *    sans une requête DNS ni un octet émis. Le contrôle est aussi fait à
 *    l'admission (Task 9) : la défense est en profondeur, ce récupérateur ne
 *    compte pas sur son appelant.
 * Couche 2. Le nom est résolu ici, par le port {@see HostAddressResolverInterface},
 *    et non par le client HTTP : une liste vide est un refus transitoire.
 * Couche 3. TOUTES les adresses rendues doivent être publiques
 *    ({@see PublicAddressPolicy}) : un hôte qui en mêle une privée à des
 *    publiques est refusé, car un client HTTP qui en choisirait une autre
 *    que celle qui a été vérifiée contournerait le contrôle.
 * Couche 4. La première adresse vérifiée est épinglée par l'option `resolve`
 *    (`CURLOPT_RESOLVE`) : la connexion va à l'adresse jugée, jamais à une
 *    seconde résolution que l'hôte aurait fait changer entre-temps
 *    (DNS rebinding). Le nom reste celui de l'URL pour le SNI et la
 *    vérification du certificat.
 * Couche 5. Le client injecté est entouré de `NoPrivateNetworkHttpClient`
 *    (`app.mcp.cimd.http_client`), un filet de plus, non un second juge
 *    suffisant. Il revérifie d'abord l'adresse épinglée (l'option `resolve`
 *    gagne sur sa propre résolution), puis l'adresse où la connexion a
 *    réellement abouti (`primary_ip`), lue dans `on_progress` : APRÈS la
 *    connexion. Cette dernière vérification coupe la lecture de la réponse,
 *    elle n'empêche pas l'émission du GET (SSRF aveugle) ; ce sont la couche 3
 *    et l'épinglage de la couche 4 qui évitent que la requête parte vers une
 *    adresse privée.
 *
 * Limite : toute `TransportExceptionInterface` est rangée en
 * {@see ClientMetadataRefusal::Unreachable}, sans lire son message. Le blocage
 * de `NoPrivateNetworkHttpClient` en est une : il est donc dit « injoignable »
 * et transitoire, et non « adresse bloquée ». Le message d'un transport peut
 * porter ce que l'hôte a choisi d'envoyer, il ne décide de rien.
 *
 * Hors des cinq couches, les gardes de la lecture (D-E) :
 *
 * a. Aucune redirection n'est suivie, et la requête est bornée en délai de
 *    connexion ({@see self::MAX_CONNECT_DURATION}), en silence
 *    ({@see self::TIMEOUT}) et en durée totale ({@see self::MAX_DURATION}).
 *
 * b. La réponse est lue avec les bornes de D-E : le statut doit être 200
 *    (3xx = {@see ClientMetadataRefusal::Redirected}, 429 et 5xx =
 *    {@see ClientMetadataRefusal::UpstreamError}, transitoire), le type
 *    `application/json` (paramètres ignorés), et le corps n'est jamais lu
 *    au-delà de {@see self::MAX_BYTES} : un `Content-Length` annoncé au-dessus
 *    de la borne est refusé avant toute lecture, sinon le flux est cumulé
 *    morceau par morceau et la connexion est fermée (`cancel()`) au premier
 *    octet de trop. Un silence plus long que {@see self::TIMEOUT} ferme aussi
 *    la connexion (transitoire).
 *
 * c. Aucune compression n'est acceptée (bombe de décompression : la borne
 *    ci-dessus mesure les octets reçus, non les octets décompressés). La
 *    requête envoie `Accept-Encoding: identity` : un en-tête `accept-encoding`
 *    explicite désactive l'inflate automatique de `symfony/http-client` (8.1,
 *    vérifié dans `CurlHttpClient::request()`, qui n'ajoute `gzip` que sans lui,
 *    et dans `CurlResponse`, `inflate = !isset(…['accept-encoding'])`). Un hôte
 *    qui compresse malgré tout est refusé avant lecture
 *    ({@see ClientMetadataRefusal::UnexpectedContentType} : ce n'est pas le
 *    contenu demandé, définitif comme un mauvais type), la connexion fermée.
 *
 * d. Aucun proxy d'environnement : `symfony/http-client` lit `https_proxy`,
 *    `HTTPS_PROXY` (et `all_proxy`) quand l'option `proxy` est absente ; la
 *    connexion irait alors au proxy, qui résout le nom lui-même, et l'adresse
 *    épinglée n'est plus celle contactée. `no_proxy: *` l'écarte (vérifié dans
 *    le vendor 8.1 : `CurlHttpClient` le passe à `CURLOPT_NOPROXY`, où `*`
 *    exclut tout hôte, et `NativeHttpClient::configureHeadersAndProxy()` rend
 *    la main sans proxy sur la règle `*`).
 *
 * Non prouvé par les tests, faute de réseau : `MockResponse` produit tout le
 * corps d'un coup à la première lecture, il ne modélise donc ni le gain de
 * `stream()` (ne pas télécharger ce qu'on refuse), ni la fermeture réelle de la
 * connexion par `cancel()`, ni `timeout` / `max_duration` appliqués par curl,
 * ni `CURLOPT_RESOLVE`, ni un `Content-Length` qui mentirait. Les tests
 * prouvent la logique d'ici (quel refus pour quelle réponse, `cancel()` appelé,
 * borne exacte) ; le comportement de curl est celui que documente
 * `symfony/http-client` (8.1) : `NoPrivateNetworkHttpClient::stream()` délègue à
 * `AsyncResponse::stream()`, qui rend le chunk de délai dépassé à
 * `isTimeout()`. `max_duration` n'est lu par aucun test.
 */
final readonly class HttpClientMetadataFetcher implements ClientMetadataFetcherInterface
{
    /** La taille maximale admise d'un document, en octets. */
    public const int MAX_BYTES = 5120;

    /** Le délai maximal sans reçu d'octet, en secondes. */
    public const float TIMEOUT = 2.0;

    /** La durée totale maximale de la requête, en secondes. */
    public const float MAX_DURATION = 3.0;

    /** Le délai maximal d'établissement de la connexion, en secondes. */
    public const float MAX_CONNECT_DURATION = 1.0;

    private const string USER_AGENT = 'cp-ghostotof (+https://github.com/ghostotof/cp-ghostotof)';

    public function __construct(
        private HttpClientInterface $client,
        private HostAddressResolverInterface $resolver,
        private PublicAddressPolicy $policy,
        private AllowedClientDomains $domains,
    ) {
    }

    public function fetch(CimdClientId $clientId): FetchedClientMetadata
    {
        // Couche 1 : un hôte hors liste n'est ni résolu ni interrogé.
        if (!$this->domains->allows($clientId)) {
            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::DomainNotAllowed);
        }

        $host = $clientId->host();
        $addresses = $this->resolver->resolve($host);

        if ([] === $addresses) {
            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::Unresolvable);
        }

        foreach ($addresses as $address) {
            if (!$this->policy->isPublic($address)) {
                throw ClientMetadataRefusedException::because(ClientMetadataRefusal::AddressBlocked);
            }
        }

        try {
            $response = $this->client->request('GET', $clientId->value(), [
                'resolve' => [$host => $addresses[0]],
                'max_redirects' => 0,
                'no_proxy' => '*',
                'timeout' => self::TIMEOUT,
                'max_connect_duration' => self::MAX_CONNECT_DURATION,
                'max_duration' => self::MAX_DURATION,
                'buffer' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'Accept-Encoding' => 'identity',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);

            // Statut, en-têtes et corps sont lus sous le même try : c'est là
            // que la connexion s'établit, donc que le transport peut échouer.
            $headers = $this->checkedHeaders($response);

            return new FetchedClientMetadata($this->boundedBody($response), $this->maxAge($headers));
        } catch (TransportExceptionInterface $transport) {
            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::Unreachable, $transport);
        }
    }

    /**
     * Vérifie le statut, le type du contenu et la taille annoncée, avant de lire
     * le corps ; rend les en-têtes (clés en minuscules).
     *
     * @return array<string, list<string>>
     */
    private function checkedHeaders(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $refusal = match (true) {
            200 === $status => null,
            $status >= 300 && $status < 400 => ClientMetadataRefusal::Redirected,
            429 === $status || $status >= 500 => ClientMetadataRefusal::UpstreamError,
            default => ClientMetadataRefusal::UnexpectedStatus,
        };

        if (null !== $refusal) {
            $response->cancel();

            throw ClientMetadataRefusedException::because($refusal);
        }

        $headers = $response->getHeaders(false);

        if (!$this->isUncompressed($headers['content-encoding'] ?? [])) {
            $response->cancel();

            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::UnexpectedContentType);
        }

        if (!$this->isJson($headers['content-type'][0] ?? '')) {
            $response->cancel();

            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::UnexpectedContentType);
        }

        $announced = $headers['content-length'][0] ?? '';

        if (1 === preg_match('/\A\d+\z/', $announced) && (int) $announced > self::MAX_BYTES) {
            $response->cancel();

            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::TooLarge);
        }

        return $headers;
    }

    /**
     * Aucun codage, ou `identity` (casse et espaces ignorés) : tout autre
     * `Content-Encoding` est un corps compressé que la borne d'octets ne
     * mesure pas. Chaque ligne d'en-tête est lue, et chaque valeur d'une ligne
     * séparée par une virgule : `identity` puis `gzip` — sur deux lignes ou sur
     * une — est un corps compressé. Une valeur vide (ligne vide, virgule en
     * trop) ne désigne aucun codage et n'est pas refusée.
     *
     * @param list<string> $contentEncodings les lignes `Content-Encoding` reçues
     */
    private function isUncompressed(array $contentEncodings): bool
    {
        foreach ($contentEncodings as $line) {
            foreach (explode(',', $line) as $value) {
                $encoding = strtolower(trim($value));

                if ('' !== $encoding && 'identity' !== $encoding) {
                    return false;
                }
            }
        }

        return true;
    }

    /** `application/json`, quels que soient la casse, les espaces et les paramètres (`; charset=…`). */
    private function isJson(string $contentType): bool
    {
        return 'application/json' === strtolower(trim(explode(';', $contentType)[0]));
    }

    /**
     * Lit le corps en flux, sans jamais en garder plus que la borne : au premier
     * octet de trop, ou au premier silence, la connexion est fermée et le reste
     * n'est pas téléchargé.
     */
    private function boundedBody(ResponseInterface $response): string
    {
        $body = '';

        foreach ($this->client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                $response->cancel();

                throw ClientMetadataRefusedException::because(ClientMetadataRefusal::Unreachable);
            }

            $body .= $chunk->getContent();

            if (\strlen($body) > self::MAX_BYTES) {
                $response->cancel();

                throw ClientMetadataRefusedException::because(ClientMetadataRefusal::TooLarge);
            }
        }

        return $body;
    }

    /**
     * La durée de fraîcheur annoncée (`max-age`, en secondes), ou null : une
     * directive entière seulement (`s-maxage` n'est pas `max-age`), de un à
     * neuf chiffres. La politique d'usage (plancher, plafond) n'est pas ici.
     *
     * @param array<string, list<string>> $headers
     */
    private function maxAge(array $headers): ?int
    {
        foreach (explode(',', strtolower(implode(',', $headers['cache-control'] ?? []))) as $directive) {
            if (1 === preg_match('/\Amax-age=(\d{1,9})\z/', trim($directive), $matches)) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
