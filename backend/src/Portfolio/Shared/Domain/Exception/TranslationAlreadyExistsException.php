<?php

declare(strict_types=1);

namespace App\Portfolio\Shared\Domain\Exception;

use ApiPlatform\Metadata\Exception\ProblemExceptionInterface;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use App\Shared\Domain\Exception\HasProblemType;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 0004 D1 : un groupe de traduction porte au plus une entrée par langue —
 * l'index unique `(translation_group, locale)` en fait une contrainte de base.
 *
 * Cette exception est ce qui empêche cette contrainte de remonter en 500. Le
 * rattachement est vérifié **avant** l'écriture, si bien qu'un `POST`/`PUT`
 * visant un groupe qui a déjà cette langue reçoit un 409 explicite : ce n'est
 * pas une saisie invalide (422) mais un conflit avec l'état existant, et
 * l'auteur a une action évidente — modifier la traduction existante.
 *
 * Le `type` stable `/errors/translation-already-exists` est ce sur quoi le
 * frontend s'appuiera : le `detail` est localisé et peut changer.
 */
final class TranslationAlreadyExistsException extends \DomainException implements ProblemExceptionInterface
{
    use HasProblemType;

    public static function forGroupAndLocale(Uuid $translationGroup, Locale $locale): self
    {
        return new self(sprintf(
            'Le groupe de traduction "%s" porte déjà une entrée en "%s".',
            $translationGroup->toRfc4122(),
            $locale->value,
        ));
    }

    protected function problemType(): string
    {
        return 'translation-already-exists';
    }

    protected function problemStatus(): int
    {
        return 409;
    }
}
