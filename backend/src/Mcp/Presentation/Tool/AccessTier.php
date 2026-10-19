<?php

declare(strict_types=1);

namespace App\Mcp\Presentation\Tool;

/**
 * Les paliers d'accès de l'ADR 0003 (vocabulaire : `CONTEXT.md`), tels qu'un
 * outil adossé les déclare : celui de sa route, que fixe `access_control`.
 *
 * Sans connexion MCP, le serveur sert les paliers anonyme et de base (ADR 0006
 * D2) ; le palier de confiance exige une connexion MCP (D3).
 */
enum AccessTier: string
{
    /** Aucune règle `access_control` : la route est publique. */
    case ANONYMOUS = 'anonymous';

    /** `ROLE_USER` : le jeton d'accès de base ou tout compte. */
    case BASE = 'base';

    /** `ROLE_TRUSTED` : accordé nominativement. */
    case TRUSTED = 'trusted';
}
