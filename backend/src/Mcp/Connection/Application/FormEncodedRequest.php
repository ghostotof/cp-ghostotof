<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le corps d'une requête au point de jeton ou de révocation (#647) : un
 * formulaire `application/x-www-form-urlencoded` (RFC 6749 §4.1.3, RFC 7009
 * §2.1), et rien d'autre.
 *
 * Le pont PSR-7 analyse aussi un corps JSON en paramètres (`PsrHttpFactory`,
 * sur le format `json`) : league servait alors un échange en JSON, et le
 * contrôle de répétition, qui lit le corps brut comme un formulaire, n'y
 * voyait rien. Ici, un autre type de contenu est un `invalid_request`, la
 * répétition est refusée sur le corps brut (QueryParameterPollution), et
 * league reçoit les paramètres **tirés de ce corps-là** par l'analyseur de
 * PHP (`parse_str`, celui des formulaires) — jamais ceux qu'un autre
 * analyseur en aurait tirés : ce qui est contrôlé est ce qui est lu.
 */
final class FormEncodedRequest
{
    private const string MEDIA_TYPE = 'application/x-www-form-urlencoded';

    private function __construct()
    {
    }

    /**
     * @param list<string> $read les paramètres que le point lit, chacun une seule fois
     *
     * @throws OAuthServerException `invalid_request`, sans redirection
     */
    public static function parse(ServerRequestInterface $request, array $read): ServerRequestInterface
    {
        // Le type, sans ses paramètres (`; charset=UTF-8`) ni la casse.
        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));

        if (self::MEDIA_TYPE !== $mediaType) {
            throw OAuthServerException::invalidRequest('Content-Type', 'Check the request body: it must be application/x-www-form-urlencoded.');
        }

        $body = (string) $request->getBody();
        QueryParameterPollution::refuse($body, $read);
        parse_str($body, $parameters);

        return $request->withParsedBody($parameters);
    }
}
