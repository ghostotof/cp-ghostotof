<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetchQuotaInterface;
use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use App\Mcp\Connection\Domain\ValueObject\CimdClientId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Adosse ClientMetadataFetchQuotaInterface à trois limiteurs de
 * config/packages/rate_limiter.yaml (fenêtre glissante, #651 D-F) :
 *
 *  - `mcp_client_metadata`, **par appelant** : 10 récupérations par 10
 *    minutes ;
 *  - `mcp_client_metadata_global`, **commun à tous les appelants** : 100 par
 *    10 minutes, sous une clé constante ;
 *  - `mcp_client_metadata_known_client`, **par client déjà déclaré**, quels
 *    que soient ses appelants : 10 par 10 minutes, sous l'empreinte SHA-256 du
 *    `client_id`.
 *
 * Le plafond commun existe parce qu'aucun préfixe ne borne un attaquant qui
 * tient un bloc plus grand que celui qu'on compte : une IPv6 est comptée par
 * son /64 (FetchQuotaKey), mais un VPS reçoit couramment un /48, soit 65 536
 * /64 et autant de quotas par appelant. Seul un compteur que tous partagent
 * borne le total des récupérations sortantes.
 *
 * Ordre des trois temps de {@see self::consume()} :
 *
 *  a. le plafond commun est **regardé sans être consommé** (`consume(0)`) :
 *     s'il ne reste aucun jeton, refus avec son `retryAfter` ;
 *  b. l'appelant est consommé : s'il est refusé, refus avec son `retryAfter` ;
 *  c. le plafond commun est consommé : refus avec son `retryAfter` s'il vient
 *     de se remplir.
 *
 * Raison : un appelant refusé, par son propre quota (b) ou par un plafond déjà
 * plein (a), ne perd rien de ce qu'il n'a pas obtenu. Refusé par son quota, il
 * ne consomme aucune unité du plafond, qu'il ne peut donc pas user en
 * insistant ; refusé par le plafond, il ne consomme aucune unité de son quota.
 * Le refus se lit sur les jetons restants, jamais sur `isAccepted()`, qui est
 * toujours vrai pour `consume(0)`.
 *
 * Course acceptée : le plafond peut se remplir entre (a) et (c), sous un autre
 * appelant. L'unité de l'appelant, consommée en (b), est alors perdue. La
 * fenêtre est de quelques millisecondes, mais **sous saturation chaque essai
 * qui passe (a) puis échoue en (c) peut y perdre une unité** : pas une fois au
 * total, un appelant qui insiste pendant qu'une attaque tient le plafond plein
 * peut voir fondre son propre quota. Accepté : ce cas ne se produit que
 * plafond déjà plein, où l'appelant est de toute façon refusé. Fermer cette
 * fenêtre demanderait un verrou commun aux deux limiteurs, hors de proportion
 * avec ce coût. `testACeilingFilledBetweenTheGlanceAndTheConsumption…` la
 * rend visible.
 *
 * {@see self::consumeForKnownClient()} (voie des clients connus, D-F : voir
 * ClientMetadataFetchQuotaInterface) **ne touche jamais le plafond commun** :
 * dix sources qui le saturent ne ferment pas la porte aux clients déjà
 * approuvés. Elle suit le même ordre en trois temps, le compteur du client à
 * la place du plafond :
 *
 *  a. le compteur du client est regardé sans être consommé ;
 *  b. l'appelant est consommé, par la même méthode que pour `consume()` ;
 *  c. le compteur du client est consommé.
 *
 * Le compteur du client existe parce que n'importe qui peut nommer un
 * `client_id` approuvé : sans lui, un attaquant passerait par cette voie, à
 * l'abri du plafond, borné seulement par ses /64. Il borne les récupérations
 * d'un même client, quels que soient ses appelants ; l'ensemble de ses clés
 * est borné par les clients approuvés. Mêmes propriétés, même course acceptée
 * que ci-dessus, compteur du client pour plafond.
 *
 * Sa clé est l'empreinte SHA-256 du `client_id`, jamais le `client_id` en
 * clair : CacheStorage garde l'identifiant du limiteur dans la valeur
 * sérialisée, et la table `cache_items` ne doit pas tenir d'URL de client.
 *
 * Les refus lèvent tous la même exception, avec le `retryAfter` du limiteur
 * qui a refusé.
 *
 * Même patron que SymfonyBaseAccessRateLimiter, à ceci près que la clé de
 * l'appelant n'est pas passée par l'appelant du quota : c'est l'adresse IP de
 * la requête principale, lue ici, pour que la couche applicative n'ait pas à
 * connaître HTTP.
 *
 * La clé de l'appelant est celle de {@see FetchQuotaKey}, non l'adresse brute :
 * **une IPv6 est comptée par son /64** (qui tient un /64 change d'adresse à
 * volonté et obtiendrait sinon un quota neuf à chaque essai), une IPv4 reste
 * entière, une IPv4 portée par une IPv6 (mappée, NAT64, 6to4, compatible)
 * compte comme cette IPv4. Pour ce limiteur seulement.
 *
 * Hors requête (une commande console, un test sans pile) ou pour une adresse
 * illisible, la clé est `unknown` : tous ces appels partagent alors un seul
 * compteur, la voie sûre (elle limite trop, jamais pas assez).
 *
 * Le décompte vit dans `cache.rate_limiter`, donc en base, sous le verrou
 * partagé entre pods du projet (RateLimiterStorageTest, ADR 0005).
 */
final readonly class SymfonyClientMetadataFetchQuota implements ClientMetadataFetchQuotaInterface
{
    /**
     * La clé du plafond commun : une seule, tous les appelants la partagent.
     * Publique pour que les tests lisent le même compteur que le code, sans la
     * recopier ; aucun appelant de production n'a de raison de la lire.
     */
    public const string GLOBAL_KEY = 'global';

    public function __construct(
        #[Autowire(service: 'limiter.mcp_client_metadata')]
        private RateLimiterFactoryInterface $rateLimiterFactory,
        #[Autowire(service: 'limiter.mcp_client_metadata_global')]
        private RateLimiterFactoryInterface $globalRateLimiterFactory,
        #[Autowire(service: 'limiter.mcp_client_metadata_known_client')]
        private RateLimiterFactoryInterface $knownClientRateLimiterFactory,
        private RequestStack $requests,
    ) {
    }

    public function consume(): void
    {
        $global = $this->globalRateLimiterFactory->create(self::GLOBAL_KEY);

        // (a) Regarder le plafond sans le consommer : plein, l'appelant ne perd rien.
        $this->refuseUnlessATokenRemains($global->consume(0));

        // (b) L'appelant : son refus ne coûte rien au plafond commun.
        $this->consumeForCaller();

        // (c) Le plafond : il a pu se remplir depuis (a), course acceptée.
        $this->refuseUnlessAccepted($global->consume());
    }

    public function consumeForKnownClient(CimdClientId $clientId): void
    {
        // L'empreinte, jamais le client_id en clair : CacheStorage le garderait dans `cache_items`.
        $client = $this->knownClientRateLimiterFactory->create(hash('sha256', $clientId->value()));

        // (a) Regarder le compteur du client sans le consommer : plein, l'appelant ne perd rien.
        $this->refuseUnlessATokenRemains($client->consume(0));

        // (b) L'appelant : son refus ne coûte rien au compteur du client. Le plafond commun n'est jamais touché.
        $this->consumeForCaller();

        // (c) Le compteur du client : il a pu se remplir depuis (a), même course acceptée.
        $this->refuseUnlessAccepted($client->consume());
    }

    /**
     * Consomme une unité du quota de l'appelant courant, sous la clé de
     * {@see FetchQuotaKey} : la seule écriture de ce quota, commune aux deux voies.
     */
    private function consumeForCaller(): void
    {
        $key = FetchQuotaKey::forAddress($this->requests->getMainRequest()?->getClientIp());

        $this->refuseUnlessAccepted($this->rateLimiterFactory->create($key)->consume());
    }

    /**
     * Le refus d'un regard (`consume(0)`) : lu sur les jetons restants,
     * `isAccepted()` étant toujours vrai pour zéro jeton.
     */
    private function refuseUnlessATokenRemains(RateLimit $glance): void
    {
        if ($glance->getRemainingTokens() < 1) {
            throw new ClientMetadataFetchRateLimitExceededException($glance->getRetryAfter());
        }
    }

    private function refuseUnlessAccepted(RateLimit $limit): void
    {
        if (!$limit->isAccepted()) {
            throw new ClientMetadataFetchRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
