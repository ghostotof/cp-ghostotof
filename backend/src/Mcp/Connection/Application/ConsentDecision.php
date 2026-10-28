<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Symfony\Component\Uid\Uuid;

/**
 * La décision d'une personne sur une demande d'autorisation (#647) : la
 * demande telle que la page de consentement l'a reçue du point d'autorisation
 * (AuthorizationRequestSummary::consentQuery()), et la réponse, accord ou
 * refus. Une commande de l'application, construite par la couche Presentation
 * depuis le corps HTTP validé.
 *
 * Rien n'y est jugé : chaque champ est rendu à AuthorizationRequestValidator
 * sous son nom OAuth ({@see self::authorizationQuery()}), le même validateur
 * que le point d'autorisation, seul juge de la demande. Un champ absent
 * (`null`) l'est aussi pour le validateur, comme un paramètre absent de
 * `GET /api/oauth/authorize`.
 *
 * **Le compte montré** (#650, F5) : l'UUID que le résumé a donné à la page
 * (`account.id`), renvoyé avec la décision. Ce n'est pas un paramètre OAuth :
 * il n'entre pas dans {@see self::authorizationQuery()}, le service le compare
 * au compte du cookie. `null` (absent ou illisible, ce que la validation du
 * corps refuse déjà) ne correspond à aucun compte.
 */
final readonly class ConsentDecision
{
    public function __construct(
        public ?string $responseType,
        public ?string $clientId,
        public ?string $redirectUri,
        public ?string $scope,
        public ?string $state,
        public ?string $codeChallenge,
        public ?string $codeChallengeMethod,
        public ?string $resource,
        public bool $approved,
        public ?Uuid $shownAccountId,
    ) {
    }

    /**
     * La demande sous ses noms OAuth, les champs absents omis : ce que le
     * validateur lirait dans la requête du point d'autorisation.
     *
     * @return array<string, string>
     */
    public function authorizationQuery(): array
    {
        return array_filter([
            'response_type' => $this->responseType,
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $this->scope,
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => $this->codeChallengeMethod,
            'resource' => $this->resource,
        ], static fn (?string $value): bool => null !== $value);
    }
}
