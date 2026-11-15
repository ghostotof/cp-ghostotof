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
 * ClientMetadataFetchQuotaInterface) ajoute un compteur par client, **qui ne
 * refuse jamais seul** : plein, il fait se replier la voie sur le chemin d'un
 * client inconnu. Ordre :
 *
 *  a. le compteur du client est regardé sans être consommé. S'il ne reste aucun
 *     jeton, repli sur {@see self::consume()} en entier (plafond regardé,
 *     appelant, plafond consommé) : l'appelant n'est consommé qu'une fois ;
 *  b. sinon l'appelant est consommé, par la même méthode que pour `consume()` ;
 *  c. le compteur du client est consommé. S'il vient de se remplir (course),
 *     c'est le plafond commun qui est consommé, **sans reprendre d'unité à
 *     l'appelant**, déjà payée en (b) ; le plafond plein refuse avec son
 *     `retryAfter`.
 *
 * Tant que le compteur a de la place, le plafond commun n'est ni regardé ni
 * consommé : dix sources qui saturent le plafond ne ferment pas la porte aux
 * clients déjà approuvés. Le compteur **décide qui paie, il ne borne pas le
 * total** : n'importe qui peut nommer un `client_id` approuvé, et sans lui un
 * attaquant passerait par cette voie à l'abri du plafond, borné seulement par
 * ses /64 ; avec lui, les dix premières récupérations de ce client dans la
 * fenêtre passent hors du plafond, les suivantes le paient. Le total des sorties
 * vers les domaines autorisés est donc borné par le plafond commun plus dix par
 * client approuvé, par fenêtre. Il **ne peut pas
 * devenir une arme contre le client** : l'épuiser (dix demandes simultanées à
 * l'expiration du cache, depuis une seule adresse) renvoie les suivants au
 * plafond commun, qui accepte tant qu'il a de la place. Fermer la porte à un
 * client connu demande de saturer son compteur **et** le plafond commun (dix
 * sources, limite déjà acceptée au plan T12) ; un client connu n'est jamais
 * plus refusé qu'un inconnu.
 *
 * Course acceptée : le compteur peut se remplir entre (a) et (c), sous un autre
 * appelant. L'appelant ne perd alors une unité sans rien obtenir que si le
 * plafond commun refuse aussi ; sinon il passe, pour une unité de quota et une
 * du plafond. Fermer la fenêtre demanderait un verrou commun aux limiteurs,
 * hors de proportion avec ce coût.
 *
 * La clé du compteur est l'empreinte SHA-256 du `client_id`
 * ({@see ClientIdFingerprint}) : une clé de longueur et d'alphabet fixes, et
 * l'URL n'est pas écrite en clair dans `cache_items` (CacheStorage garde
 * l'identifiant du limiteur dans la valeur sérialisée). Ce n'est pas un
 * secret : le `client_id` d'un client approuvé est une URL publique, que
 * quiconque en connaît la candidate recalcule en empreinte. Aucune donnée
 * personnelle n'est stockée.
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
        $client = $this->knownClientRateLimiterFactory->create(ClientIdFingerprint::of($clientId));

        // (a) Regarder le compteur du client sans le consommer. Plein, il ne refuse pas : repli
        // sur tout le chemin d'un client inconnu, l'appelant n'y étant consommé qu'une fois.
        if ($client->consume(0)->getRemainingTokens() < 1) {
            $this->consume();

            return;
        }

        // (b) L'appelant : son refus ne coûte rien au compteur du client ni au plafond.
        $this->consumeForCaller();

        // (c) Le compteur du client. Il a pu se remplir depuis (a) : course acceptée, le plafond
        // prend alors l'appel, sans reprendre d'unité à l'appelant, déjà consommé en (b).
        if (!$client->consume()->isAccepted()) {
            $this->refuseUnlessAccepted($this->globalRateLimiterFactory->create(self::GLOBAL_KEY)->consume());
        }
    }

    /**
     * Consomme une unité du quota de l'appelant courant, sous la clé de
     * {@see FetchQuotaKey} : la seule écriture de ce quota, commune aux deux voies et à son repli.
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
