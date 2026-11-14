<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetchQuotaInterface;
use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse ClientMetadataFetchQuotaInterface à deux limiteurs de
 * config/packages/rate_limiter.yaml (fenêtre glissante, #651 D-F) :
 *
 *  - `mcp_client_metadata`, **par appelant** : 10 récupérations par 10
 *    minutes ;
 *  - `mcp_client_metadata_global`, **commun à tous les appelants** : 100 par
 *    10 minutes, sous une clé constante.
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
 * appelant. L'unité de l'appelant, consommée en (b), est alors perdue. Fermer
 * cette fenêtre demanderait un verrou commun aux deux limiteurs, hors de
 * proportion avec une unité sur dix perdue, une fois, au pire.
 *
 * Les trois refus lèvent la même exception, avec le `retryAfter` du limiteur
 * qui a refusé.
 *
 * Même patron que SymfonyBaseAccessRateLimiter, à ceci près que la clé de
 * l'appelant n'est pas passée par l'appelant du quota : c'est l'adresse IP de
 * la requête principale, lue ici, pour que la couche applicative n'ait pas à
 * connaître HTTP.
 *
 * La clé est celle de {@see FetchQuotaKey}, non l'adresse brute : **une IPv6
 * est comptée par son /64** (qui tient un /64 change d'adresse à volonté et
 * obtiendrait sinon un quota neuf à chaque essai), une IPv4 reste entière, une
 * IPv4 portée par une IPv6 (mappée, NAT64, 6to4, compatible) compte comme cette
 * IPv4. Pour ce limiteur seulement.
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
    /** La clé du plafond commun : une seule, tous les appelants la partagent. */
    private const string GLOBAL_KEY = 'global';

    public function __construct(
        #[Autowire(service: 'limiter.mcp_client_metadata')]
        private RateLimiterFactory $rateLimiterFactory,
        #[Autowire(service: 'limiter.mcp_client_metadata_global')]
        private RateLimiterFactory $globalRateLimiterFactory,
        private RequestStack $requests,
    ) {
    }

    public function consume(): void
    {
        $key = FetchQuotaKey::forAddress($this->requests->getMainRequest()?->getClientIp());
        $global = $this->globalRateLimiterFactory->create(self::GLOBAL_KEY);

        // (a) Regarder le plafond sans le consommer : plein, l'appelant ne perd rien.
        $glance = $global->consume(0);
        if ($glance->getRemainingTokens() < 1) {
            throw new ClientMetadataFetchRateLimitExceededException($glance->getRetryAfter());
        }

        // (b) L'appelant : son refus ne coûte rien au plafond commun.
        $this->refuseUnlessAccepted($this->rateLimiterFactory->create($key)->consume());

        // (c) Le plafond : il a pu se remplir depuis (a), course acceptée.
        $this->refuseUnlessAccepted($global->consume());
    }

    private function refuseUnlessAccepted(RateLimit $limit): void
    {
        if (!$limit->isAccepted()) {
            throw new ClientMetadataFetchRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
