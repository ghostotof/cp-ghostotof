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
        self::refuseSurroundingBlanks($parameters, $read);

        return $request->withParsedBody($parameters);
    }

    /**
     * La valeur d'un paramètre du corps analysé, quand c'est une chaîne —
     * vide comprise, chaque lecteur juge la sienne ; `null` sinon : un corps
     * non analysé, un paramètre absent, ou reçu en tableau (`code[]=…`).
     * Écrite une fois (#726, CR7) pour le point de jeton et ses
     * collaborateurs, qui lisent tous le corps que {@see self::parse()} a
     * tiré du corps brut contrôlé — le même que league a lu.
     *
     * La valeur peut être un secret (un code, un vérificateur, un jeton de
     * rafraîchissement) : rendue, jamais reçue en paramètre — `$name` est un
     * nom, jamais une valeur —, elle n'appelle aucun `#[SensitiveParameter]`
     * ici ; c'est à celui qui la reçoit ensuite d'en porter un.
     */
    public static function stringParameter(ServerRequestInterface $request, string $name): ?string
    {
        $body = $request->getParsedBody();
        $value = \is_array($body) ? ($body[$name] ?? null) : null;

        return \is_string($value) ? $value : null;
    }

    /**
     * Une valeur lue entourée de blancs est refusée, jamais corrigée (#725) :
     * league la lit sans eux (`AbstractGrant::parseParam()` fait un `trim()`),
     * tout autre lecteur du même corps la lirait avec. Un code entouré d'une
     * espace atteignait league et échappait à l'audit de son refus ; un
     * `client_id` suivi de 50 000 tabulations entrait tel quel au journal.
     * Les blancs sont ceux de `trim()`, ceux que league retire.
     *
     * @param array<array-key, mixed> $parameters
     * @param list<string>            $read
     *
     * @throws OAuthServerException `invalid_request`
     */
    private static function refuseSurroundingBlanks(array $parameters, array $read): void
    {
        foreach ($read as $name) {
            $value = $parameters[$name] ?? null;

            if (\is_string($value) && trim($value) !== $value) {
                throw OAuthServerException::invalidRequest($name, \sprintf('Check the `%s` parameter: it must not start or end with a blank.', $name));
            }
        }
    }
}
