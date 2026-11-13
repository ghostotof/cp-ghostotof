<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetcherInterface;
use App\Mcp\Connection\Application\Cimd\FetchedClientMetadata;
use App\Mcp\Connection\Domain\Exception\ClientMetadataRefusedException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use App\Mcp\Connection\Domain\ValueObject\ClientMetadataRefusal;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Demande à l'hôte d'un `client_id` CIMD le document qu'il publie (#651,
 * plan de la tâche 12, D-D). L'hôte vient d'un tiers : tout ce qui suit sert
 * à ce que la requête n'atteigne jamais le réseau du serveur.
 *
 * 1. Le nom est résolu ici, par le port {@see HostAddressResolverInterface},
 *    et non par le client HTTP : une liste vide est un refus transitoire.
 * 2. TOUTES les adresses rendues doivent être publiques
 *    ({@see PublicAddressPolicy}) : un hôte qui en mêle une privée à des
 *    publiques est refusé, car un client HTTP qui en choisirait une autre
 *    que celle qui a été vérifiée contournerait le contrôle.
 * 3. La première adresse vérifiée est épinglée par l'option `resolve`
 *    (`CURLOPT_RESOLVE`) : la connexion va à l'adresse jugée, jamais à une
 *    seconde résolution que l'hôte aurait fait changer entre-temps
 *    (DNS rebinding). Le nom reste celui de l'URL pour le SNI et la
 *    vérification du certificat.
 * 4. Aucune redirection n'est suivie, et la requête est bornée en délai de
 *    connexion et en durée totale.
 * 5. Le client injecté est entouré de `NoPrivateNetworkHttpClient`
 *    (`app.mcp.cimd.http_client`), qui revérifie l'adresse épinglée et celle
 *    où la connexion a réellement abouti (`primary_ip`) : cette couche tient
 *    seule si les précédentes avaient une faille.
 *
 * Limite : toute `TransportExceptionInterface` est rangée en
 * {@see ClientMetadataRefusal::Unreachable}, sans lire son message. Le blocage
 * de `NoPrivateNetworkHttpClient` en est une : il est donc dit « injoignable »
 * et transitoire, et non « adresse bloquée ». Le message d'un transport peut
 * porter ce que l'hôte a choisi d'envoyer, il ne décide de rien.
 *
 * Le statut, le type du contenu et la lecture bornée du corps sont l'objet de
 * la tâche suivante : ici le corps est rendu tel quel.
 */
final readonly class HttpClientMetadataFetcher implements ClientMetadataFetcherInterface
{
    /** La taille maximale admise d'un document, en octets. */
    public const int MAX_BYTES = 5120;

    /** Le délai maximal sans reçu d'octet, en secondes. */
    public const float TIMEOUT = 2.0;

    /** La durée totale maximale de la requête, en secondes. */
    public const float MAX_DURATION = 3.0;

    private const string USER_AGENT = 'cp-ghostotof (+https://github.com/ghostotof/cp-ghostotof)';

    public function __construct(
        private HttpClientInterface $client,
        private HostAddressResolverInterface $resolver,
        private PublicAddressPolicy $policy,
    ) {
    }

    public function fetch(CimdClientId $clientId): FetchedClientMetadata
    {
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
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
                'buffer' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'User-Agent' => self::USER_AGENT,
                ],
            ]);

            // Le corps est lu ici, sous le même try : c'est à la lecture que
            // la connexion s'établit, donc que le transport peut échouer.
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface $transport) {
            throw ClientMetadataRefusedException::because(ClientMetadataRefusal::Unreachable, $transport);
        }

        return new FetchedClientMetadata($body, null);
    }
}
