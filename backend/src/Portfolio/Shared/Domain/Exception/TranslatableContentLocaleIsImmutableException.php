<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use DomainException;

/**
 * Exception métier levée quand une modification tente de changer la langue
 * d'un contenu localisé (issue #582), sur les huit contextes qui partagent la
 * règle des groupes de traduction (spec 0004).
 *
 * Une entrée ne change jamais de langue : elle est la version d'un groupe de
 * traduction dans sa langue, unique dans ce groupe (index
 * `(translation_group, locale)`), et aucune entité n'expose de setter. Pour
 * une autre langue, on crée la version correspondante (« Créer la version
 * XX »).
 *
 * Le refus est explicite plutôt que silencieux, comme la catégorie d'une
 * carte « moi » (`AboutMeCardCategoryIsImmutableException`, #578) et le slug
 * d'un produit surveillé : jusqu'à #582, le `PUT` validait `locale`,
 * l'ignorait et répondait 200 (#170 R2) — une réponse qui laissait croire au
 * client que son changement avait été pris en compte.
 *
 * Une seule exception pour les huit contextes, comme
 * `TranslationAlreadyExistsException` : une même règle, un seul `type` stable
 * pour un client. 409 : le corps contredit l'état de la ressource.
 */
final class TranslatableContentLocaleIsImmutableException extends DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    /**
     * Message littéral : il devient le `detail` renvoyé au client, qui ne doit
     * jamais reprendre ce que la requête a envoyé (ProblemDetailStaysStaticTest).
     * Les deux langues n'y figurent donc pas ; le client a la sienne, et l'id
     * de l'entrée est dans l'URL.
     */
    public static function refused(): self
    {
        return new self('La langue d\'une entrée ne peut pas changer : créez sa version dans l\'autre langue, puis supprimez celle-ci si besoin.');
    }

    protected function problemType(): string
    {
        return 'locale-immutable';
    }

    protected function problemStatus(): int
    {
        return 409;
    }
}
