<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Application\ExchangedCodeConnectionInterface;
use App\Mcp\Connection\Application\FormEncodedRequest;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Uuid;

/**
 * La connexion qu'un code présenté à l'échange désigne (#726, S3), lue par
 * GrantPayloadReader : la charge que league a chiffrée sous la clé du serveur,
 * déchiffrée par le même code que league — un code qu'il déchiffre est un code
 * qu'il a émis.
 *
 * Le corps lu est celui que FormEncodedRequest a tiré du corps brut contrôlé,
 * le même que league a lu. Coût : un déchiffrement de plus que league, à
 * chaque échange réussi seulement (CodeExchangeSupersession ne l'appelle que
 * pour un grant `authorization_code` que league a servi).
 */
final readonly class LeagueExchangedCodeConnection implements ExchangedCodeConnectionInterface
{
    public function __construct(
        private GrantPayloadReader $payloadReader,
    ) {
    }

    public function connectionIdOf(ServerRequestInterface $request): ?Uuid
    {
        $code = FormEncodedRequest::stringParameter($request, 'code');

        return null === $code ? null : $this->payloadReader->connectionIdOf($code);
    }
}
