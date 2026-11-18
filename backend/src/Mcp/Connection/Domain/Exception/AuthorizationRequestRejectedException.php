<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;
use Throwable;

/**
 * Une demande d'autorisation que le consentement ne peut plus accorder
 * (#647, spec 0007 phase 2) : revalidée au moment où la personne la voit ou y
 * répond, elle ne l'est plus — redirection modifiée après le point
 * d'autorisation, client inconnu ou retiré, PKCE ou ressource absents.
 *
 * Toujours un 400 problem+json, **jamais une redirection** : au consentement,
 * la page de la personne parle au serveur, pas le client. Renvoyer vers une
 * redirection que la revalidation n'a pas confirmée, c'est précisément ce que
 * la revalidation existe pour empêcher.
 *
 * Messages constants, qui ne citent rien de la demande (le `detail` est rendu
 * tel quel, ProblemDetailStaysStaticTest) : le refus de league, gardé en
 * `previous`, dit au journal du noyau lequel des contrôles a refusé — ses
 * messages ne recopient jamais une valeur reçue
 * ({@see \App\Mcp\Connection\Application\AuthorizationRequestValidator}).
 */
final class AuthorizationRequestRejectedException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    /** Le refus du validateur, quel qu'il soit. */
    public static function onRevalidation(Throwable $refusal): self
    {
        return new self('La demande d\'autorisation n\'est pas valable : aucune connexion ne peut être accordée sur elle.', 0, $refusal);
    }

    /**
     * Le client validé à l'instant n'est plus enregistré au moment de créer la
     * connexion : retiré entre les deux lectures, le cas d'une course.
     */
    public static function clientNoLongerRegistered(): self
    {
        return new self('Le client de la demande d\'autorisation n\'est plus enregistré : aucune connexion ne peut lui être accordée.');
    }

    protected function problemType(): string
    {
        return 'authorization-request-rejected';
    }

    protected function problemStatus(): int
    {
        return 400;
    }
}
