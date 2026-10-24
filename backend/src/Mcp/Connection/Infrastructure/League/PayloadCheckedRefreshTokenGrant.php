<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use Exception;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;

/**
 * Le grant de rafraîchissement de league, qui vérifie la forme de la charge
 * avant que league ne la lise (#647).
 *
 * league chiffre sous la **même** clé ses codes d'autorisation et ses jetons
 * de rafraîchissement : un code présenté comme `refresh_token` se déchiffre,
 * porte le bon `client_id` et une échéance future, puis league lit
 * `refresh_token_id`, que la charge d'un code n'a pas — un avertissement
 * « clé absente », un `null` passé au dépôt typé `string`, un 500 à la main
 * de quiconque détient un code. Ici, une charge qui n'a pas la forme exacte
 * d'un rafraîchissement (celle que `BearerTokenResponse` écrit) est le refus
 * de league pour un jeton invalide : `invalid_grant`, 400.
 *
 * Le contrôle précède la validation de league, qui reste entière derrière
 * (client, échéance, révocation) ; un jeton indéchiffrable lui est laissé, et
 * reçoit son refus à elle. Le prix : une charge valide est déchiffrée deux
 * fois (un déchiffrement defuse sous une clé déjà dérivée, sans étirement),
 * plutôt qu'une copie de `validateOldRefreshToken()` qui divergerait à la
 * prochaine version de league.
 *
 * L'inverse — un jeton de rafraîchissement présenté comme code — est déjà
 * refusé par league (`auth_code_id` absent, `invalid_request`).
 */
final class PayloadCheckedRefreshTokenGrant extends RefreshTokenGrant
{
    /**
     * @return array<string, mixed>
     *
     * @throws OAuthServerException `invalid_grant` quand la charge n'a pas la forme d'un rafraîchissement ; les refus de league sinon
     */
    protected function validateOldRefreshToken(ServerRequestInterface $request, string $clientId): array
    {
        $encryptedRefreshToken = $this->getRequestParameter('refresh_token', $request);

        if (null !== $encryptedRefreshToken && false === $this->hasRefreshTokenShape($encryptedRefreshToken)) {
            throw OAuthServerException::invalidRefreshToken('Check the `refresh_token` parameter: it is not a refresh token.');
        }

        return parent::validateOldRefreshToken($request, $clientId);
    }

    /**
     * Vrai si la charge déchiffrée a la forme d'un rafraîchissement, faux si
     * elle ne l'a pas, `null` si elle ne se déchiffre pas (laissé à league).
     */
    private function hasRefreshTokenShape(#[SensitiveParameter] string $encryptedRefreshToken): ?bool
    {
        try {
            $payload = json_decode($this->decrypt($encryptedRefreshToken), true);
        } catch (Exception) {
            return null;
        }

        return \is_array($payload)
            && \is_string($payload['client_id'] ?? null)
            && \is_int($payload['expire_time'] ?? null)
            && $this->isNonEmptyString($payload['refresh_token_id'] ?? null)
            && $this->isNonEmptyString($payload['access_token_id'] ?? null)
            && (\is_string($payload['user_id'] ?? null) || \is_int($payload['user_id'] ?? null))
            && $this->isListOfStrings($payload['scopes'] ?? null);
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return \is_string($value) && '' !== $value;
    }

    private function isListOfStrings(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return false;
        }

        return array_all($value, static fn(mixed $item): bool => \is_string($item));
    }
}
