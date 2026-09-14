<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;

/**
 * Spec 0004 D4 : `PUT …/order` exige l'ensemble exact des clés du périmètre.
 * Une clé que le périmètre ne porte pas — parce qu'elle n'a jamais existé, a
 * été supprimée entre le chargement de la page et l'enregistrement, ou est
 * répétée dans la liste — n'est pas un état incohérent en base : c'est une
 * saisie invalide (422), et le frontend recharge plutôt que de réessayer
 * l'envoi automatiquement.
 *
 * Choix documenté (voir `OrderAssignerTest`) : une clé **dupliquée** lève
 * cette même exception plutôt qu'une exception dédiée. Une fois consommée par
 * sa première occurrence, la seconde ne se distingue plus d'une clé qui
 * n'aurait jamais été dans le périmètre — dans les deux cas la liste n'est pas
 * une permutation valide du périmètre, et la réponse HTTP est la même 422.
 */
final class UnknownOrderEntryException extends \DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function forKey(string $key): self
    {
        return new self(sprintf(
            'La clé "%s" ne correspond à aucune entrée du périmètre (ou apparaît plus d\'une fois).',
            $key,
        ));
    }

    protected function problemType(): string
    {
        return 'unknown-order-entry';
    }

    protected function problemStatus(): int
    {
        return 422;
    }
}
