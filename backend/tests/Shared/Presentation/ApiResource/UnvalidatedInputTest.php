<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\ApiResource;

use App\Ai\Translation\Presentation\ApiResource\BackofficeTranslationResource;
use App\Portfolio\About\Presentation\ApiResource\BackofficeAboutMeCardOrderResource;
use App\Shared\Presentation\ApiResource\UnvalidatedInputException;
use PHPUnit\Framework\TestCase;

/**
 * Les DTO d'écriture rendent leur entrée sous la forme que la validation
 * garantit (`keys()`, `validatedCategory()`, `validatedFields()`). Une valeur
 * qui contredit cette garantie est un défaut du pipeline de validation, jamais
 * une saisie (issue #338) : elle est refusée sous un nom dédié, plutôt que
 * filtrée en silence ou levée en \LogicException anonyme.
 *
 * Les DTO sont construits directement, sans passer par le validateur : c'est
 * précisément le cas « la validation n'a pas tranché » que la garde couvre.
 */
final class UnvalidatedInputTest extends TestCase
{
    public function testANonTextualOrderKeyIsRefused(): void
    {
        $this->expectException(UnvalidatedInputException::class);

        new BackofficeAboutMeCardOrderResource(category: 'hobby', groups: ['019968a0-0000-7000-8000-000000000001', 42])->keys();
    }

    public function testAnAbsentCategoryIsRefused(): void
    {
        $this->expectException(UnvalidatedInputException::class);

        // Seule la catégorie manque : le reste du corps est conforme.
        new BackofficeAboutMeCardOrderResource(groups: ['019968a0-0000-7000-8000-000000000001'])->validatedCategory();
    }

    public function testANonTextualTranslationFieldIsRefusedAndNamed(): void
    {
        $this->expectException(UnvalidatedInputException::class);
        // Le nom du champ est ce qui permet de remonter au défaut de validation.
        $this->expectExceptionMessage('"summary"');

        new BackofficeTranslationResource(sourceLocale: 'fr', targetLocale: 'en', fields: ['title' => 'Bonjour', 'summary' => ['imbriqué']])->validatedFields();
    }
}
