<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le port du remplacement (#726, S3) : la connexion que désigne le code
 * présenté à un échange, lue dans sa charge chiffrée — ce que seul
 * Infrastructure\League sait faire (le serveur seul détient la clé).
 *
 * La décision, elle, est à CodeExchangeSupersession : garder cette connexion,
 * révoquer les autres du couple, cause `superseded`, à l'instant de
 * l'horloge. Le port ne juge rien, ni le grant ni la connexion : il lit.
 */
interface ExchangedCodeConnectionInterface
{
    /**
     * @return Uuid|null la connexion que la charge du `code` du corps désigne (son `user_id`) ; `null` pour un
     *                   corps sans `code` en chaîne, ou un code illisible — une autre clé, un texte quelconque,
     *                   une charge sans connexion
     */
    public function connectionIdOf(ServerRequestInterface $request): ?Uuid;
}
