<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Entity\McpConnection;

/**
 * Achève une demande d'autorisation validée (#647) : l'étape que league tient
 * (« l'utilisateur » de la demande, l'accord, l'émission du code) et la forme
 * de sa réponse, ramenée à la redirection vers le client. Mise en œuvre dans
 * Infrastructure\League, qui seul connaît league ; l'application n'en voit
 * que cette interface.
 *
 * Ni l'une ni l'autre méthode n'ajoute `iss` (RFC 9207) : l'appelant le fait
 * par IssParameter, une fois pour les deux issues.
 */
interface AuthorizationCompleterInterface
{
    /**
     * Accorde la demande à `$connection` : league émet le code, que son dépôt
     * persiste en le rattachant à la connexion — elle doit donc être déjà
     * écrite, dans la même transaction.
     *
     * @return string la redirection vers le client, `code` et `state` compris
     */
    public function approve(AuthorizationRequestSummary $summary, McpConnection $connection): string;

    /**
     * Refuse la demande : aucune connexion, aucun code.
     *
     * @return string la redirection vers le client, `error=access_denied` et `state` compris
     */
    public function deny(AuthorizationRequestSummary $summary): string;
}
