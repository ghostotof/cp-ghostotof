<?php

declare(strict_types=1);

namespace App\Portfolio\About\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
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

    /**
     * Message littéral : il devient le `detail` renvoyé au client, qui ne doit
     * jamais reprendre ce que la requête a envoyé (ProblemDetailStaysStaticTest).
     * Les deux catégories n'y figurent donc pas ; le client a la sienne, et
     * l'id de la carte est dans l'URL.
     */
    public static function refused(): self
    {
        return new self('La catégorie d\'une carte ne peut pas changer : créez la carte dans la bonne catégorie, ses versions dans les autres langues aussi, puis supprimez les anciennes.');
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
