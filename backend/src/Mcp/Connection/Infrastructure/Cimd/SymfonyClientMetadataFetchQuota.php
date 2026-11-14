<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Cimd;

use App\Mcp\Connection\Application\Cimd\ClientMetadataFetchQuotaInterface;
use App\Mcp\Connection\Domain\Exception\ClientMetadataFetchRateLimitExceededException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Adosse ClientMetadataFetchQuotaInterface à deux limiteurs de
 * config/packages/rate_limiter.yaml (fenêtre glissante, #651 D-F), consommés
 * dans cet ordre :
 *
 *  1. `mcp_client_metadata`, **par appelant** : 10 récupérations par 10
 *     minutes ;
 *  2. `mcp_client_metadata_global`, **commun à tous les appelants** : 100 par
 *     10 minutes, sous une clé constante.
 *
 * Le plafond commun existe parce qu'aucun préfixe ne borne un attaquant qui
 * tient un bloc plus grand que celui qu'on compte : une IPv6 est comptée par
 * son /64 (FetchQuotaKey), mais un VPS reçoit couramment un /48, soit 65 536
 * /64 et autant de quotas par appelant. Seul un compteur que tous partagent
 * borne le total des récupérations sortantes.
 *
 * L'appelant passe d'abord : un appelant déjà refusé par son propre quota ne
 * consomme aucune unité du plafond commun, qu'il ne peut donc pas user en
 * insistant. Les deux refus lèvent la même exception, avec le `retryAfter` du
 * limiteur qui a refusé.
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

        // L'appelant d'abord : son refus ne doit rien coûter au plafond commun.
        $this->consumeOrRefuse($this->rateLimiterFactory, $key);
        $this->consumeOrRefuse($this->globalRateLimiterFactory, self::GLOBAL_KEY);
    }

    private function consumeOrRefuse(RateLimiterFactory $factory, string $key): void
    {
        $limit = $factory->create($key)->consume();

        if (!$limit->isAccepted()) {
            throw new ClientMetadataFetchRateLimitExceededException($limit->getRetryAfter());
        }
    }
}
