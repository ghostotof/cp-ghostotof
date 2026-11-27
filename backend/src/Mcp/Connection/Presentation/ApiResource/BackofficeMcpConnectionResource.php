<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Mcp\Connection\Application\AdministeredMcpConnectionView;
use App\Mcp\Connection\Infrastructure\ApiPlatform\BackofficeMcpConnectionProvider;
use DateTimeInterface;

/**
 * La connexion MCP vue par l'administration (#652, spec 0007 T13) : toutes
 * celles de tous les comptes, en **lecture seule**. La révocation par
 * l'administrateur n'existe pas ici (la personne révoque les siennes).
 *
 * Une seule opération, la collection : l'identifiant n'est **pas** celui d'API
 * Platform (`identifier: false`). Sans cela, le framework synthétiserait une
 * route d'élément `/api/backoffice_mcp_connections/{id}` que rien n'a décidé
 * d'exposer ; `BackofficeMcpConnectionResourceTest` garde l'absence de route.
 *
 * Ne porte **jamais** de jeton, d'empreinte, de secret ni de code
 * d'autorisation : la vue applicative n'en a pas, et le test des clés le tient.
 * Les valeurs nulles restent dans la réponse (défaut d'API Platform : omises).
 *
 * `expiresAt` est rendue même pour une connexion révoquée : c'est l'échéance
 * qu'elle aurait eue, pas l'instant où elle a cessé.
 */
#[ApiResource(
    shortName: 'BackofficeMcpConnection',
    // Garde les valeurs nulles dans la réponse (défaut API Platform : omises) :
    // lastUsedAt, revokedAt et revocationCause sont des champs du contrat.
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(uriTemplate: '/backoffice/mcp/connections', provider: BackofficeMcpConnectionProvider::class),
    ],
)]
final readonly class BackofficeMcpConnectionResource
{
    /**
     * @param string  $id                 identifiant de la connexion (UUID RFC 4122), sans route d'élément
     * @param string  $account            nom du compte propriétaire
     * @param string  $clientRegistration `preregistered` ou `cimd`
     * @param string  $state              `active`, `expired` ou `revoked`
     * @param ?string $revocationCause    nulle pour une connexion non révoquée, abandonnée comprise
     */
    public function __construct(
        #[ApiProperty(identifier: false)]
        public string $id,
        public string $account,
        public string $clientId,
        public string $clientName,
        public string $clientRegistration,
        public string $grantedAt,
        public bool $codeExchanged,
        public ?string $lastUsedAt,
        public string $expiresAt,
        public string $state,
        public ?string $revokedAt,
        public ?string $revocationCause,
    ) {
    }

    public static function fromView(AdministeredMcpConnectionView $view): self
    {
        return new self(
            id: $view->id->toRfc4122(),
            account: $view->accountUsername,
            clientId: $view->clientId,
            clientName: $view->clientName,
            clientRegistration: $view->clientRegistration->value,
            grantedAt: $view->grantedAt->format(DateTimeInterface::ATOM),
            codeExchanged: $view->codeExchanged,
            lastUsedAt: $view->lastUsedAt?->format(DateTimeInterface::ATOM),
            expiresAt: $view->expiresAt->format(DateTimeInterface::ATOM),
            state: $view->state->value,
            revokedAt: $view->revokedAt?->format(DateTimeInterface::ATOM),
            revocationCause: $view->revocationCause,
        );
    }
}
