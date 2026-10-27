<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use App\Mcp\Connection\Application\OwnMcpConnectionsInterface;
use App\Mcp\Connection\Application\OwnMcpConnectionView;
use App\Security\User\Domain\Entity\CpgUser;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * « Mes connexions MCP » (#650, spec 0007 tâche 11) : les connexions que la
 * personne du palier de confiance a accordées, lues sous `/api/me/`.
 *
 * - `GET /api/me/mcp-connections` : la liste, toutes les connexions du compte
 *   — actives, expirées, révoquées gardées six mois — avec leur état et, si
 *   elles sont coupées, quand et pourquoi.
 *
 * Réservé au palier de confiance par l'`access_control` `^/api/me(/|$)`
 * existant (`ROLE_TRUSTED`, `ROLE_SUPER` par la hiérarchie) : aucune règle
 * nouvelle, aucune entrée de `PUBLIC_PATHS` ni de `BASE_TIER_PATHS`. Le compte
 * est celui du cookie, lu par `#[CurrentUser]` : jamais un identifiant que le
 * client enverrait. Comme `ConsentController`, une réponse JSON écrite à la
 * main (pas d'API Platform), `Cache-Control: no-store` — la liste dit quels
 * clients lisent le CV nominatif au nom de la personne.
 *
 * Léger par construction : appel de l'interface, mise en forme. Aucun calcul
 * (l'état et l'échéance viennent de l'Application).
 */
final readonly class OwnMcpConnectionsController
{
    public function __construct(
        private OwnMcpConnectionsInterface $ownConnections,
    ) {
    }

    /**
     * Un CpgUser par construction : l'access_control exige ROLE_TRUSTED, que le
     * jeton du palier de base ne porte jamais ; `#[CurrentUser]` le vérifie
     * plutôt que de le supposer.
     */
    #[Route('/api/me/mcp-connections', name: 'me_mcp_connections_list', methods: ['GET'])]
    public function list(#[CurrentUser] CpgUser $account): JsonResponse
    {
        $response = new JsonResponse([
            'connections' => array_map($this->entryOf(...), $this->ownConnections->of($account)),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function entryOf(OwnMcpConnectionView $view): array
    {
        return [
            'id' => $view->id->toRfc4122(),
            'client' => ['id' => $view->clientId, 'name' => $view->clientName],
            'grantedAt' => $this->atom($view->grantedAt),
            'lastUsedAt' => $this->atomOrNull($view->lastUsedAt),
            'expiresAt' => $this->atom($view->expiresAt),
            'state' => $view->state->value,
            'revokedAt' => $this->atomOrNull($view->revokedAt),
            'revocationCause' => $view->revocationCause?->value,
        ];
    }

    private function atom(DateTimeImmutable $instant): string
    {
        return $instant->format(DateTimeInterface::ATOM);
    }

    private function atomOrNull(?DateTimeImmutable $instant): ?string
    {
        return null === $instant ? null : $this->atom($instant);
    }
}
