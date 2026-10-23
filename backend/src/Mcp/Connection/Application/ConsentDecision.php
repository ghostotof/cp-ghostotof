<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpClient;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de `POST /api/oauth/consent` (#647) : la demande d'autorisation telle
 * que la page de consentement l'a reçue du point d'autorisation
 * (AuthorizationRequestSummary::consentQuery()), et la réponse de la
 * personne.
 *
 * Rien n'y est jugé : chaque champ est rendu à AuthorizationRequestValidator
 * sous son nom OAuth ({@see self::authorizationQuery()}), le même validateur
 * que le point d'autorisation, seul juge de la demande. Les contraintes ne
 * font que borner la taille de ce qui lui est transmis. Un champ absent
 * (`null`) l'est aussi pour le validateur, comme un paramètre absent de
 * `GET /api/oauth/authorize`.
 *
 * `approved` est exigé : une décision qui ne dit ni oui ni non est refusée
 * (422), plutôt que lue comme l'un des deux.
 */
final class ConsentDecision
{
    /** `code` est la seule valeur admise ; la borne laisse passer un refus lisible. */
    public const int RESPONSE_TYPE_MAX_LENGTH = 32;

    /** Une redirection ou une ressource : une URL, bornée bien au-delà de l'usage. */
    public const int URI_MAX_LENGTH = 2048;

    /** La colonne `scope` de la connexion ({@see \App\Mcp\Connection\Domain\Entity\McpConnection}). */
    public const int SCOPE_MAX_LENGTH = 255;

    /** RFC 7636 §4.2 : le défi S256 fait 43 caractères, un vérificateur au plus 128. */
    public const int CODE_CHALLENGE_MAX_LENGTH = 128;

    /** `S256` est la seule méthode admise. */
    public const int CODE_CHALLENGE_METHOD_MAX_LENGTH = 16;

    public function __construct(
        #[Assert\Length(max: self::RESPONSE_TYPE_MAX_LENGTH)]
        public ?string $responseType = null,
        #[Assert\Length(max: McpClient::CLIENT_ID_MAX_LENGTH)]
        public ?string $clientId = null,
        #[Assert\Length(max: self::URI_MAX_LENGTH)]
        public ?string $redirectUri = null,
        #[Assert\Length(max: self::SCOPE_MAX_LENGTH)]
        public ?string $scope = null,
        // En octets, l'unité du validateur (VSCHAR, RFC 6749 annexe A.5).
        #[Assert\Length(max: AuthorizationRequestValidator::STATE_MAX_LENGTH, countUnit: Assert\Length::COUNT_BYTES)]
        public ?string $state = null,
        #[Assert\Length(max: self::CODE_CHALLENGE_MAX_LENGTH)]
        public ?string $codeChallenge = null,
        #[Assert\Length(max: self::CODE_CHALLENGE_METHOD_MAX_LENGTH)]
        public ?string $codeChallengeMethod = null,
        #[Assert\Length(max: self::URI_MAX_LENGTH)]
        public ?string $resource = null,
        #[Assert\NotNull]
        public ?bool $approved = null,
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
        ], static fn(?string $value): bool => null !== $value);
    }

    /** Un accord explicite seulement : une décision non validée (`null`) vaut refus. */
    public function isApproved(): bool
    {
        return true === $this->approved;
    }
}
