<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Aucune réponse du point MCP n'est stockée (#648, relecture de sécurité) :
 * `Cache-Control: no-store` sur **toute** réponse de {@see McpEndpoint::PATH},
 * quelle qu'en soit la méthode, le statut ou l'auteur (le SDK, le contrôleur,
 * un refus d'avant le SDK, le firewall, une erreur du framework).
 *
 * La réponse JSON du SDK ne porte aucun en-tête de cache, et Symfony lui
 * calculait sa valeur par défaut, `no-cache, private` (mesuré) : `private`
 * exclut les caches partagés, mais `no-cache` laisse un cache privé — un proxy
 * d'entreprise côté client, le cache HTTP d'une bibliothèque cliente —
 * **stocker** la réponse, et le corpus nominatif est la donnée la plus
 * sensible que le site serve. Les réponses OAuth, le 401 différé, le 401 d'un
 * jeton refusé et le flux du GET portaient déjà `no-store` ; c'est désormais
 * la règle du point entier. Le point entier plutôt que les seules lectures
 * authentifiées : une règle qui trie par outil ou par identité est une règle
 * qu'un outil de plus oublie, et un client MCP ne gagne rien à mettre en
 * cache une réponse JSON-RPC.
 *
 * Le chemin canonique (CanonicalPath, issue #77), comme les gardes voisines :
 * `/api/%6Dcp` est routé vers le serveur MCP, sa réponse est la sienne.
 * Priorité -1024 : après les écouteurs qui écrivent les en-têtes de cache
 * (l'attribut `#[Cache]` à -10, la session à -1000, absente ici) — la
 * directive est le dernier mot. `addCacheControlDirective()` garde les
 * directives déjà posées (`private` reste calculé par Symfony).
 */
#[AsEventListener(event: ResponseEvent::class, priority: -1024)]
final readonly class NoStoreResponseListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || McpEndpoint::PATH !== CanonicalPath::of($event->getRequest())) {
            return;
        }

        $event->getResponse()->headers->addCacheControlDirective('no-store');
    }
}
