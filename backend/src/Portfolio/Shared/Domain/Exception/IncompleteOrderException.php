<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;

/**
 * Spec 0004 D4 : `PUT …/order` exige l'ensemble exact des clés du périmètre.
 * Une clé du périmètre absente de la liste envoyée signifie, la plupart du
 * temps, qu'une entrée a été créée entre le chargement de la page et
 * l'enregistrement de l'ordre : ce n'est pas une erreur serveur, c'est un
 * contrôle de concurrence optimiste sans version ni horodatage — le serveur
 * refuse (422), le frontend recharge la liste et le dit.
 */
final class IncompleteOrderException extends \DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    /**
     * @param list<string> $missingKeys
     */
    public static function forKeys(array $missingKeys): self
    {
        return new self(sprintf(
            'Il manque %d entrée(s) du périmètre dans la liste envoyée : %s.',
            \count($missingKeys),
            implode(', ', $missingKeys),
        ));
    }

    protected function problemType(): string
    {
        return 'incomplete-order';
    }

    protected function problemStatus(): int
    {
        return 422;
    }
}
