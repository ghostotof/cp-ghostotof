<?php

declare(strict_types=1);

namespace App\Mcp\Application;

/**
 * Ce qui désigne l'outil protégé du serveur MCP (#648, ADR 0006 D3), écrit une
 * fois : l'outil le prend pour nom
 * ({@see \App\Mcp\Presentation\Tool\GetNominativeCorpusTool}), et le 401
 * différé le reconnaît par lui
 * ({@see \App\Mcp\Infrastructure\Http\DeferredAuthorizationListener}) — un
 * renommage d'un côté seul laisserait l'appel anonyme passer sans le défi.
 *
 * Le préfixe `openai/` est celui des clés `_meta` que ChatGPT ajoute à ses
 * appels : un message qui en porte une passe le point avant le SDK, et l'outil
 * rend alors le refus dans la forme que ce client lit. **Il ne choisit que la
 * forme du refus, jamais l'accès** : l'accès se décide sur l'identité que
 * l'authentificateur du firewall `mcp` pose, seule (ADR 0006 D4), rejugée par
 * {@see NominativeCorpusReaderInterface::open()}.
 */
final class ProtectedTools
{
    /** Le corpus nominatif, réservé au palier de confiance par une connexion MCP. */
    public const string NOMINATIVE_CORPUS = 'get_nominative_corpus';

    /** Le préfixe des clés `_meta` qui marquent un appel de ChatGPT. */
    public const string OPENAI_META_PREFIX = 'openai/';

    private function __construct()
    {
    }
}
