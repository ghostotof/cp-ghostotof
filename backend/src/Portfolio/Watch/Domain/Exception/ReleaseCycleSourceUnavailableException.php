<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Exception;

/**
 * La source des cycles de vie n'a pas pu être exploitée : injoignable, en
 * erreur, ou répondant dans une forme inattendue.
 *
 * Elle ne remonte jamais jusqu'au visiteur : le rafraîchissement se solde par
 * un échec journalisé et le snapshot précédent reste servi.
 */
final class ReleaseCycleSourceUnavailableException extends \DomainException
{
    public static function forTransportFailure(string $slug, \Throwable $previous): self
    {
        return new self(
            sprintf('Source de cycles de vie injoignable pour "%s" : %s', $slug, $previous->getMessage()),
            0,
            $previous,
        );
    }

    public static function forUnexpectedStatus(string $slug, int $statusCode): self
    {
        return new self(sprintf(
            'La source de cycles de vie a répondu %d pour "%s".',
            $statusCode,
            $slug,
        ));
    }

    public static function forUnexpectedShape(string $slug): self
    {
        return new self(sprintf(
            'Réponse inexploitable pour "%s" : aucune liste de cycles de vie trouvée.',
            $slug,
        ));
    }
}
