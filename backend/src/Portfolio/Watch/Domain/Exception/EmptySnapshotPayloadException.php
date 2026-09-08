<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Domain\Exception;

use App\Portfolio\Watch\Domain\ValueObject\WatchSnapshotType;

/**
 * Exception métier levée quand on tente d'écrire un snapshot vide.
 *
 * C'est l'invariant central de la fonctionnalité, rendu structurel plutôt que
 * confié à la vigilance de l'appelant : lorsqu'une source externe est
 * injoignable, la donnée de la veille doit survivre. Un rafraîchissement qui
 * n'a rien rapporté n'est pas un rafraîchissement, c'est une panne — et une
 * panne ne doit pas effacer ce que l'on savait déjà.
 */
final class EmptySnapshotPayloadException extends \DomainException
{
    public static function forType(WatchSnapshotType $type): self
    {
        return new self(sprintf(
            'Refus d\'écrire un snapshot "%s" vide : la donnée précédente reste en place.',
            $type->value,
        ));
    }
}
