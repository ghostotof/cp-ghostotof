<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Le remplacement d'un accord par le suivant, au premier échange réussi de
 * son code (#649 décision 2, déplacé par #726, D2). Mis en œuvre dans
 * Infrastructure\League, qui seul sait lire la connexion que la charge
 * chiffrée du code désigne.
 *
 * L'approbation ne révoque rien : un accord dont le code n'est jamais échangé
 * (le client abandonne, la redirection échoue) laisse l'accord précédent
 * vivant. C'est l'échange qui prouve que le client a reçu la nouvelle
 * connexion ; il révoque alors les **autres** connexions actives du même
 * couple compte/client, cause `superseded`.
 */
interface CodeExchangeSupersessionInterface
{
    /**
     * À appeler **dans** la transaction du point de jeton, après la réponse
     * de league : la connexion du code y est déjà verrouillée `FOR UPDATE`
     * (`AuthCodeRepository::isAuthCodeRevoked()`), et la révocation suit le
     * sort de l'échange — annulé, rien n'est révoqué. L'audit revient à
     * l'appelant, après le COMMIT : chaque ligne nomme la connexion gardée
     * (`supersededBy`, #726 B3), d'où Supersession plutôt que la seule liste.
     *
     * @return Supersession|null la connexion gardée et les connexions remplacées (une liste vide pour un
     *                           couple sans autre connexion active) ; `null` pour un `grant_type` autre que
     *                           `authorization_code` ou un code dont la charge ne désigne aucune connexion connue
     */
    public function supersedeOthersOf(ServerRequestInterface $request): ?Supersession;
}
