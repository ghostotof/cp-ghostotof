<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Shared\Domain\Exception\HasProblemType;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 0004 D3 : on ne se rattache qu'à un contenu qui existe. Un groupe
 * qu'aucune entrée du périmètre ne porte est une saisie invalide (422), pas un
 * 404 : la ressource visée par la requête — l'entrée à créer ou à modifier —
 * existe ou va exister ; c'est l'un de ses champs qui ne convient pas.
 *
 * « Du périmètre » est le mot important pour les cartes « moi » : leur
 * périmètre d'ordre est la **catégorie**, un groupe d'une autre catégorie leur
 * est donc inconnu — un groupe ne peut pas chevaucher deux catégories, sans
 * quoi une même position vaudrait dans deux tableaux différents.
 */
final class UnknownTranslationGroupException extends \DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function forGroup(Uuid $translationGroup): self
    {
        return new self(sprintf(
            'Aucune entrée de ce périmètre ne porte le groupe de traduction "%s".',
            $translationGroup->toRfc4122(),
        ));
    }

    protected function problemType(): string
    {
        return 'unknown-translation-group';
    }

    protected function problemStatus(): int
    {
        return 422;
    }
}
