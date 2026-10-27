<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Psr\Http\Message\ResponseInterface;

/**
 * Ce que la transaction du point de jeton a écrit (#726), rendu à
 * TokenEndpoint pour qu'il l'audite après le COMMIT : la réponse de league,
 * jetons compris, et le remplacement que le premier échange du code a écrit
 * — la connexion gardée et celles qu'elle remplace. Interne au point de
 * jeton, jamais persisté ni exposé.
 *
 * Nommé par ce qu'il est, l'issue d'un échange au point de jeton (#726, S7),
 * pas par les seuls jetons : il porte aussi le remplacement.
 */
final readonly class TokenExchangeOutcome
{
    /**
     * @param ResponseInterface $response     la réponse de league, celle que le client reçoit
     * @param Supersession|null $supersession le remplacement écrit par l'échange d'un code ; `null` pour un
     *                                        rafraîchissement ou un code qui ne désigne aucune connexion connue
     */
    public function __construct(
        public ResponseInterface $response,
        public ?Supersession $supersession,
    ) {
    }
}
