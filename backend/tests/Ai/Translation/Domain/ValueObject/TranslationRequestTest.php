<?php

declare(strict_types=1);

namespace App\Tests\Ai\Translation\Domain\ValueObject;

use App\Ai\Translation\Domain\Exception\InvalidTranslationRequestException;
use App\Ai\Translation\Domain\ValueObject\TranslationRequest;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use PHPUnit\Framework\TestCase;

final class TranslationRequestTest extends TestCase
{
    public function testExposesLocalesAndFieldNames(): void
    {
        $request = new TranslationRequest(Locale::FR, Locale::EN, ['title' => 'Panne', 'impact' => 'Formulaire en 500']);

        self::assertSame(Locale::FR, $request->sourceLocale);
        self::assertSame(Locale::EN, $request->targetLocale);
        self::assertSame(['title', 'impact'], $request->fieldNames());
        self::assertSame(['title' => 'Panne', 'impact' => 'Formulaire en 500'], $request->fields);
    }

    public function testRefusesIdenticalLocales(): void
    {
        $this->expectException(InvalidTranslationRequestException::class);

        new TranslationRequest(Locale::FR, Locale::FR, ['title' => 'Panne']);
    }

    public function testRefusesAnEmptyDictionary(): void
    {
        $this->expectException(InvalidTranslationRequestException::class);

        new TranslationRequest(Locale::FR, Locale::EN, []);
    }

    public function testRefusesABlankValue(): void
    {
        $this->expectException(InvalidTranslationRequestException::class);

        new TranslationRequest(Locale::FR, Locale::EN, ['title' => '   ']);
    }
}
