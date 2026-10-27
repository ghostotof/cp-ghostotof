<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Le remplacement d'un accord par le suivant, au premier échange réussi de
 * son code (#649 décision 2, déplacé par #726, D2). Décidé en Application
 * (CodeExchangeSupersession, #726, S3) ; seule la lecture de la connexion que
 * la charge chiffrée du code désigne est à Infrastructure\League, derrière
 * ExchangedCodeConnectionInterface.
 *
 * L'approbation ne révoque rien : un accord dont le code n'est jamais échangé
 * (le client abandonne, la redirection échoue) laisse l'accord précédent
 * vivant. C'est l'échange qui prouve que le client a reçu la nouvelle
 * connexion ; il révoque alors les **autres** connexions actives du même
 * couple compte/client, cause `superseded`.
 *
 * **Deux temps, de part et d'autre du COMMIT**, que le point de jeton
 * ordonne — lui seul tient la transaction : le remplacement dedans, son audit
 * après (#726, S4 : le collaborateur « après la réponse », ici plutôt que
 * dans TokenEndpoint, qui n'écrit plus lui-même au journal de sécurité).
 */
interface CodeExchangeSupersessionInterface
{
    /**
     * À appeler **dans** la transaction du point de jeton, après la réponse
     * de league : la connexion du code y est déjà verrouillée `FOR UPDATE`
     * (`AuthCodeRepository::isAuthCodeRevoked()`), et la révocation suit le
     * sort de l'échange — annulé, rien n'est révoqué. Rien n'est audité ici :
     * une ligne écrite avant le COMMIT survivrait à un ROLLBACK.
     *
     * @return Supersession|null la connexion gardée et les connexions remplacées (une liste vide pour un
     *                           couple sans autre connexion active) ; `null` pour un `grant_type` autre que
     *                           `authorization_code` ou un code dont la charge ne désigne aucune connexion connue
     */
    public function supersedeOthersOf(ServerRequestInterface $request): ?Supersession;

    /**
     * À appeler **après** le COMMIT de la transaction qui a rendu
     * `$supersession` : une ligne `mcp-connection-revoked` par connexion
     * remplacée, qui nomme la connexion gardée (`supersededBy`, #726 B3) —
     * d'où Supersession plutôt que la seule liste. **Ne lève jamais** : une
     * panne de l'audit ne change pas la réponse du point de jeton (la règle de
     * #725).
     */
    public function auditSuperseded(Supersession $supersession): void;
}
