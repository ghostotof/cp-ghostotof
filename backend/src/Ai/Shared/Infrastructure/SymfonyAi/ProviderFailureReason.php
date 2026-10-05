<?php

declare(strict_types=1);

namespace App\Ai\Shared\Infrastructure\SymfonyAi;

/**
 * Nature d'un échec du fournisseur de modèle, dans un vocabulaire qui ne
 * dépend ni du fournisseur ni de la formulation de son bridge (issue #308) :
 * une requête sur le journal (`providerFailure`) vaut pour Anthropic comme
 * pour Scaleway.
 *
 * Journalisée seulement : aucune décision métier n'en dépend aujourd'hui,
 * d'où sa place en Infrastructure. Le jour où un message à l'utilisateur en
 * dépendra (« quota du fournisseur atteint » plutôt que « indisponible »),
 * elle rejoindra une couche Domain.
 *
 * Évolution prévue : un pattern Strategy, selon les besoins. Aujourd'hui,
 * ProviderFailure classe seule les échecs des deux bridges, parce que leurs
 * formats se distinguent sans savoir de quel fournisseur ils viennent (préfixe
 * du message, classe d'exception commune à symfony/ai-platform). Le jour où ce
 * n'est plus vrai — un troisième fournisseur dont un format, une classe ou un
 * type d'erreur signifie autre chose que chez les deux premiers, ou une table
 * REASON_BY_ERROR_TYPE qui devrait trancher entre deux sens pour un même
 * type —, extraire une `ProviderFailureClassifierInterface` dans Ai/Shared, la
 * décliner par fournisseur (`AnthropicFailureClassifier`,
 * `ScalewayFailureClassifier`, injectées par service à côté de leur agent),
 * et garder dans une base commune ce qui ne varie pas (classe d'exception,
 * statut HTTP, « Unexpected response code »). Cette énumération en reste le
 * vocabulaire de sortie, inchangé : les requêtes sur le journal tiennent.
 * Pas avant : sans variation réelle, l'interface n'ajouterait que du câblage
 * (règle « design patterns sur besoin avéré »).
 */
enum ProviderFailureReason: string
{
    /** Clé absente, invalide ou révoquée (401). */
    case Authentication = 'authentication';
    /** Clé valide privée d'un droit, ou projet hors de sa portée (403). */
    case PermissionDenied = 'permission-denied';
    /** Modèle retiré ou mal nommé (404). */
    case ModelNotFound = 'model-not-found';
    /** Quota du fournisseur atteint (429) — pas le quota du site, qui ne part jamais chez lui. */
    case RateLimited = 'rate-limited';
    /** Panne ou surcharge chez le fournisseur (5xx, `overloaded_error`, `api_error`). */
    case ServerError = 'server-error';
    /** Requête refusée pour ce qu'elle contient : invalide, trop longue, filtrée (400). */
    case InputRejected = 'input-rejected';
    /** Transport coupé, ou flux terminé sans fin annoncée. */
    case Interrupted = 'interrupted';
    /** Rien de reconnaissable : un format de bridge nouveau, ou un bogue de ce côté-ci. */
    case Unknown = 'unknown';
}
