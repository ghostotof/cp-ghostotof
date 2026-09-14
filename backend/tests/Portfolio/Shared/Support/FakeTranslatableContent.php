<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Support;

use App\Portfolio\Shared\Domain\TranslatableContent;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Component\Uid\Uuid;

/**
 * Contenu localisé minimal, sans Doctrine : `ContentPlacement` est un service
 * pur, son test ne doit dépendre d'aucun des huit contextes qui l'utilisent —
 * en prendre un au hasard ferait de son test l'otage de ce contexte-là.
 */
final class FakeTranslatableContent implements TranslatableContent
{
    private Uuid $translationGroup;

    public function __construct(
        private readonly Locale $locale,
        private int $position = 0,
        ?Uuid $translationGroup = null,
    ) {
        $this->translationGroup = $translationGroup ?? Uuid::v7();
    }

    public function getLocale(): Locale
    {
        return $this->locale;
    }

    public function getTranslationGroup(): Uuid
    {
        return $this->translationGroup;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function attachToTranslationGroup(Uuid $translationGroup): void
    {
        $this->translationGroup = $translationGroup;
    }

    public function detachFromTranslationGroup(): void
    {
        $this->translationGroup = Uuid::v7();
    }

    public function orderingKey(): string
    {
        return $this->translationGroup->toRfc4122();
    }

    public function moveToPosition(int $position): void
    {
        $this->position = $position;
    }
}
