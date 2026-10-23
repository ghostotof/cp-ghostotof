<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Exception\AuthorizationRequestRejectedException;
use App\Security\User\Domain\Entity\CpgUser;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Le consentement à une connexion MCP (#647, spec 0007 phase 2) : ce qu'une
 * personne du palier de confiance voit d'une demande d'autorisation, puis sa
 * réponse. Chaque appel revalide la demande par AuthorizationRequestValidatorInterface,
 * le juge du point d'autorisation : rien n'est gardé côté serveur entre les
 * deux, et la page ne renvoie que ce que le serveur a jugé.
 *
 * Interface parce que le contrôleur dépend d'elle et non de sa mise en œuvre
 * (règle du dépôt : contrôleur → interface → service).
 */
interface ConsentServiceInterface
{
    /**
     * La demande, revalidée telle que reçue, résumée pour la page.
     *
     * @throws AuthorizationRequestRejectedException quand elle n'est pas (ou plus) valable
     */
    public function summarize(ServerRequestInterface $request): AuthorizationRequestSummary;

    /**
     * La réponse de `$account` à la demande, revalidée : l'accord crée la
     * connexion et son code d'autorisation, le refus ne crée rien.
     *
     * @return string la redirection vers le client — `code` (accord) ou
     *                `error=access_denied` (refus), `state` et `iss` (RFC 9207)
     *
     * @throws AuthorizationRequestRejectedException quand elle n'est pas (ou plus) valable
     */
    public function decide(ConsentDecision $decision, CpgUser $account): string;
}
