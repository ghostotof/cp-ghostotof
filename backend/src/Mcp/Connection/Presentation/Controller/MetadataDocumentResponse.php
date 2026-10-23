<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Presentation\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * La réponse commune aux deux documents de métadonnées OAuth : du JSON public,
 * cachable une heure. Écrite une fois pour que les deux documents ne
 * divergent pas dans leur politique de cache.
 *
 * Une heure : les documents ne changent qu'avec un déploiement (ou un
 * changement de base publique), et un client MCP les relit à chaque
 * découverte ; un cache plus long retarderait d'autant la prise en compte
 * d'un changement d'URL. `public` : rien n'y dépend de l'appelant — les
 * firewalls de ces chemins n'ont aucun authentificateur.
 */
final class MetadataDocumentResponse
{
    public const int MAX_AGE_SECONDS = 3600;

    /**
     * @param array<string, mixed> $document
     */
    public static function of(array $document): JsonResponse
    {
        return new JsonResponse($document)
            ->setPublic()
            ->setMaxAge(self::MAX_AGE_SECONDS);
    }
}
