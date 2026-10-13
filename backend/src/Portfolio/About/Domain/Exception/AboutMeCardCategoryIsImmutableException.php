<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Portfolio\About\Domain\ValueObject\AboutMeCardCategory;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * Exception métier levée quand une modification tente de changer la catégorie
 * d'une carte « À propos de moi » (issue #578).
 *
 * Une carte ne change pas de catégorie : `AboutMeCard` n'a pas de setter, la
 * catégorie est le périmètre d'ordre de la carte, et les membres d'un groupe
 * de traduction la partagent (#501). Pour ranger une carte ailleurs, on la
 * crée dans la bonne catégorie, puis on supprime l'ancienne.
 *
 * Le refus est explicite plutôt que silencieux, sur le modèle du slug d'un
 * produit surveillé (`WatchedProductSlugIsImmutableException`) : avant #578,
 * le `PUT` validait la catégorie, l'ignorait et répondait 200 — et le
 * formulaire d'alors, qui vidait « Version de » au changement de catégorie,
 * détachait la carte de sa traduction sans le dire.
 *
 * 409 comme le slug : le corps contredit l'état de la ressource. Un `type`
 * stable, parce que le frontend doit le distinguer de l'autre 409 des cartes,
 * `translation-already-exists`.
 */
final class AboutMeCardCategoryIsImmutableException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function forCategories(AboutMeCardCategory $current, AboutMeCardCategory $submitted): self
    {
        return new self(\sprintf(
            'La catégorie d\'une carte ne peut pas changer ("%s" → "%s") : créez la carte dans la bonne catégorie, puis supprimez celle-ci.',
            $current->value,
            $submitted->value,
        ));
    }

    protected function problemType(): string
    {
        return 'me-card-category-immutable';
    }

    protected function problemStatus(): int
    {
        return 409;
    }
}
