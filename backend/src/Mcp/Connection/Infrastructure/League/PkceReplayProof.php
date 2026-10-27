<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\FormEncodedRequest;
use App\Mcp\Connection\Application\ReplayProofInterface;
use League\OAuth2\Server\CodeChallengeVerifiers\S256Verifier;
use Psr\Http\Message\ServerRequestInterface;

/**
 * La preuve PKCE d'un rejeu de code (#726) : le `code_verifier` du corps,
 * haché en `S256`, rend le `code_challenge` que la charge chiffrée du code
 * porte (RFC 7636 §4.6).
 *
 * Le défi est lu par GrantPayloadReader (le serveur seul détient la clé, un
 * code qu'il déchiffre est un code qu'il a émis), la vérification est celle
 * de league, `S256Verifier` — la même que l'échange, comparée en
 * `hash_equals()` : jamais une copie de la règle, qui pourrait diverger de
 * celle qui a validé le premier échange.
 *
 * Le corps lu est celui que FormEncodedRequest a tiré du corps brut contrôlé
 * (aucune valeur entourée de blancs, aucun paramètre répété), le même que
 * league a lu : la preuve porte sur ce que league a jugé.
 */
final readonly class PkceReplayProof implements ReplayProofInterface
{
    public function __construct(
        private GrantPayloadReader $payloadReader,
    ) {
    }

    public function provesPossession(ServerRequestInterface $request): bool
    {
        $code = FormEncodedRequest::stringParameter($request, 'code');
        $verifier = FormEncodedRequest::stringParameter($request, 'code_verifier');
        if (null === $code || null === $verifier) {
            return false;
        }

        $challenge = $this->payloadReader->s256ChallengeOf($code);

        // Instancié ici, pas injecté (#726, S5, décision) : un objet de league
        // sans état ni dépendance, pas un service métier — l'injecter
        // demanderait de déclarer en service une classe du vendor pour n'y
        // rien configurer, et ne permettrait aucune substitution utile : la
        // preuve doit être la vérification même de league, jamais un double.
        return null !== $challenge && new S256Verifier()->verifyCodeChallenge($verifier, $challenge);
    }
}
