<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\ValueObject;

/**
 * La cause pour laquelle le document de métadonnées d'un client CIMD n'a pas
 * été admis (#651, plan de la tâche 12, D-G). Elle nomme le refus côté
 * serveur (audit `mcp-client-metadata-refused`) et décide de la réponse :
 * un refus définitif est un `invalid_client`, un refus transitoire un 503 ou
 * un 429 que le client peut réessayer ({@see self::isTransient()}).
 *
 * La valeur est un mot stable (kebab), sans rien de ce que l'hôte distant a
 * répondu : elle peut entrer dans un message d'exception ou une ligne de
 * journal telle quelle.
 */
enum ClientMetadataRefusal: string
{
    /** Le domaine du `client_id` ne figure pas dans la liste des domaines admis. */
    case DomainNotAllowed = 'domain-not-allowed';

    /** Trop de documents demandés dans la fenêtre : la demande n'est pas faite. */
    case Throttled = 'throttled';

    /** L'hôte ne résout vers aucune adresse IPv4 (ou la résolution a échoué). */
    case Unresolvable = 'unresolvable';

    /** Une des adresses de l'hôte n'est pas publique : aucune requête n'est émise. */
    case AddressBlocked = 'address-blocked';

    /** La connexion a échoué (délai, refus, réseau privé atteint, TLS). */
    case Unreachable = 'unreachable';

    /** L'hôte a répondu par une redirection, jamais suivie. */
    case Redirected = 'redirected';

    /** L'hôte a répondu autre chose que 200 (hors redirection et erreur serveur). */
    case UnexpectedStatus = 'unexpected-status';

    /** L'hôte a répondu 429 ou une erreur serveur (5xx). */
    case UpstreamError = 'upstream-error';

    /** Le document n'est pas servi en `application/json`. */
    case UnexpectedContentType = 'unexpected-content-type';

    /** Le document dépasse la taille bornée. */
    case TooLarge = 'too-large';

    /** Le document n'est pas un document de métadonnées valide. */
    case InvalidDocument = 'invalid-document';

    /**
     * Une ligne pré-enregistrée porte l'identifiant CIMD d'un domaine de la liste
     * des domaines admis (#652, règle D-C) : elle masquerait le vrai client de ce
     * domaine, sous un nom et des redirections que le document ne dit pas. Écrite
     * avant que le domaine entre dans la liste (le backoffice et la CLI la
     * refusent désormais à la création), elle est refusée au point d'autorisation
     * jusqu'à sa suppression. Définitive : réessayer ne change rien tant que la
     * ligne existe.
     */
    case PreregisteredRowShadowsVerifiedDomain = 'preregistered-row-shadows-verified-domain';

    /**
     * Vrai quand un nouvel essai plus tard peut réussir sans que le client
     * change rien : la cause tient à l'état passager du réseau ou de l'hôte,
     * pas à ce que le `client_id` est.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::Throttled, self::Unresolvable, self::Unreachable, self::UpstreamError => true,
            default => false,
        };
    }
}
