<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Dto;

use App\Mcp\Connection\Application\AuthorizationRequestValidator;
use App\Mcp\Connection\Application\ConsentDecision;
use App\Mcp\Connection\Domain\Entity\McpClient;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de `POST /api/oauth/consent` (#647), validé par le Validator avant
 * tout appel (`#[MapRequestPayload]`), puis rendu à l'application en
 * ConsentDecision ({@see self::toDecision()}).
 *
 * Les contraintes ne jugent pas la demande — c'est le rôle du validateur
 * d'autorisation, que le service appelle — : elles bornent la taille de ce
 * qui lui est transmis, et exigent `approved`, pour qu'une décision qui ne dit
 * ni oui ni non soit refusée (422) plutôt que lue comme l'un des deux. Il
 * exige de même le compte que la page a montré (`accountId`).
 */
final class ConsentRequest
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
        // Le compte que la page a montré (`account.id` du résumé, #650, F5) :
        // exigé, jamais toléré absent — une garde facultative se contourne en
        // omettant le champ. Un UUID, sa forme seulement ; le service juge s'il
        // est celui du cookie.
        // NotBlank : Uuid et Length laissent passer la chaîne vide, que
        // toDecision() ne saurait pas lire (une 500 au lieu d'un 422).
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $accountId = null,
    ) {
    }

    /**
     * La décision de l'application. Un accord explicite seulement : un
     * `approved` nul, qu'une validation aurait refusé, vaut refus — le côté
     * sûr, qui ne crée rien. De même un compte montré absent ou illisible, que
     * la validation aurait refusé, devient `null` — que le service refuse
     * comme un compte qui a changé : jamais un accord sans compte comparable.
     */
    public function toDecision(): ConsentDecision
    {
        return new ConsentDecision(
            responseType: $this->responseType,
            clientId: $this->clientId,
            redirectUri: $this->redirectUri,
            scope: $this->scope,
            state: $this->state,
            codeChallenge: $this->codeChallenge,
            codeChallengeMethod: $this->codeChallengeMethod,
            resource: $this->resource,
            approved: true === $this->approved,
            shownAccountId: null !== $this->accountId && Uuid::isValid($this->accountId) ? Uuid::fromString($this->accountId) : null,
        );
    }
}
