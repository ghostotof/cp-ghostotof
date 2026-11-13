<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

use App\Mcp\Connection\Domain\Exception\InvalidMcpClientIdException;

/**
 * Un `client_id` qui est l'URL du document de métadonnées de son client
 * (CIMD, #651 ; draft-ietf-oauth-client-id-metadata-document-03, §3).
 *
 * Le draft (§3) exige le schéma `https`, un chemin, aucun segment `.` ni `..`,
 * aucun fragment ni nom d'utilisateur ; il déconseille la requête et permet le
 * port. Cette forme est plus étroite, parce qu'elle est la première des cinq
 * couches contre le SSRF (plan de la tâche 12, D-D) — ce qui la passe est la
 * seule chose pour laquelle une récupération peut partir :
 *  - un identifiant de client admissible d'abord ({@see McpClientId} : 255
 *    caractères au plus, un alphabet sans `?`, `#`, `%`, `@`, crochet ni
 *    blanc), donc ni requête, ni fragment, ni encodage pour cent, ni
 *    informations d'utilisateur, ni adresse IPv6 ;
 *  - le préfixe `https://` exact, casse comprise (`HTTPS://` est refusé) ;
 *  - un nom d'hôte DNS en minuscules, d'au moins deux labels, dont le dernier
 *    commence par une lettre : ni adresse en notation pointée, ni entier
 *    décimal, ni nom sans point (`localhost`), ni nom enraciné ;
 *  - **aucun port**, que le draft permet : le refuser borne la cible de la
 *    récupération au port 443 de l'hôte, quel que soit le domaine autorisé —
 *    un `client_id` ne désigne jamais un autre service du même hôte ;
 *  - un chemin, autre que `/`, sans segment vide, `.` ni `..` : un segment
 *    vide (`//x`, barre finale) est lu à sa façon par chaque serveur, et
 *    l'URL demandée pourrait ne pas être la ressource que le document
 *    déclare.
 *
 * Aucune normalisation : la valeur gardée est la chaîne reçue, à l'octet
 * près — c'est elle que le document doit déclarer comme `client_id`, par
 * égalité de chaînes. Une chaîne hors de cette forme n'est pas une erreur :
 * elle n'est qu'un identifiant de ligne (`tryFrom()` rend `null`), celui d'un
 * client pré-enregistré ou d'un client inconnu.
 *
 * La forme ne dit rien du domaine : {@see AllowedClientDomains} juge l'hôte.
 */
final readonly class CimdClientId
{
    /**
     * Le nom d'hôte d'un `client_id` CIMD. Publique : la liste des domaines
     * autorisés refuse à sa construction une entrée qu'aucun hôte ne pourrait
     * égaler, sur cette même définition.
     */
    public const string HOST_PATTERN = '#\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z#';

    private const string SCHEME_PREFIX = 'https://';

    /** Les segments qu'un chemin ne porte jamais : vide, `.` et `..`. */
    private const array REFUSED_PATH_SEGMENTS = ['', '.', '..'];

    private function __construct(
        private McpClientId $clientId,
        private string $host,
    ) {
    }

    public static function tryFrom(string $clientId): ?self
    {
        try {
            $rowIdentifier = McpClientId::fromString($clientId);
        } catch (InvalidMcpClientIdException) {
            return null;
        }

        if (!str_starts_with($clientId, self::SCHEME_PREFIX)) {
            return null;
        }

        // L'autorité jusqu'à la première barre, puis les segments du chemin.
        // Sans barre, aucun segment : l'URL n'a pas de chemin. Réduit à `/`,
        // le chemin a un seul segment, vide.
        $parts = explode('/', substr($clientId, \strlen(self::SCHEME_PREFIX)));
        $host = $parts[0];
        $pathSegments = \array_slice($parts, 1);

        // Un port (`claude.ai:443`) tombe ici : `:` n'est pas d'un nom d'hôte.
        if (1 !== preg_match(self::HOST_PATTERN, $host) || [] === $pathSegments) {
            return null;
        }

        foreach ($pathSegments as $segment) {
            if (\in_array($segment, self::REFUSED_PATH_SEGMENTS, true)) {
                return null;
            }
        }

        return new self($rowIdentifier, $host);
    }

    /** La chaîne reçue, à l'octet près. */
    public function value(): string
    {
        return (string) $this->clientId;
    }

    public function host(): string
    {
        return $this->host;
    }

    /** L'identifiant de la ligne `mcp_client` de ce client : la même chaîne. */
    public function toMcpClientId(): McpClientId
    {
        return $this->clientId;
    }
}
