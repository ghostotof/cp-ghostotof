<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Fabrique la réponse commune aux deux documents de métadonnées OAuth : du
 * JSON public, cachable une heure. Écrite une fois pour que les deux
 * documents ne divergent pas dans leur politique de cache. Une méthode
 * statique sans état, non instanciable : rien à injecter ni à substituer.
 *
 * Une heure : les documents ne changent qu'avec un déploiement (ou un
 * changement de base publique), et un client MCP les relit à chaque
 * découverte ; un cache plus long retarderait d'autant la prise en compte
 * d'un changement d'URL. `public` : rien n'y dépend de l'appelant — les
 * firewalls de ces chemins n'ont aucun authentificateur.
 */
final class MetadataDocumentResponseFactory
{
    public const int MAX_AGE_SECONDS = 3600;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function create(array $document): JsonResponse
    {
        return new JsonResponse($document)
            ->setPublic()
            ->setMaxAge(self::MAX_AGE_SECONDS);
    }
}
