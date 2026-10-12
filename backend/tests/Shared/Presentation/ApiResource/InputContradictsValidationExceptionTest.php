<?php

declare(strict_types=1);

namespace App\Tests\Shared\Presentation\ApiResource;

use App\Shared\Presentation\ApiResource\InputContradictsValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Le nom d'un champ non textuel vient du client, et le message part dans le
 * journal `critical` (issue #338, revue de sécurité). Quand on en arrive là,
 * la validation n'a pas borné ce nom non plus : il est réduit à ce que
 * `BackofficeTranslationResource::FIELD_NAME_PATTERN` admet (lettres et
 * chiffres, 40 caractères), pour qu'aucune clé ne puisse forger une ligne de
 * journal ni l'inonder.
 */
final class InputContradictsValidationExceptionTest extends TestCase
{
    public function testAFieldNameIsQuotedWhenItIsAlreadyHarmless(): void
    {
        self::assertStringContainsString('"summary"', InputContradictsValidationException::nonTextualField('summary')->getMessage());
    }

    public function testControlCharactersOfAFieldNameNeverReachTheLog(): void
    {
        $message = InputContradictsValidationException::nonTextualField("title\nCRITICAL forged")->getMessage();

        self::assertStringContainsString('"title?CRITICAL?forged"', $message);
        self::assertStringNotContainsString("\n", $message);
    }

    public function testAnOversizedFieldNameIsTruncated(): void
    {
        $message = InputContradictsValidationException::nonTextualField(str_repeat('x', 500))->getMessage();

        self::assertStringContainsString('"'.str_repeat('x', 40).'…"', $message);
        self::assertStringNotContainsString(str_repeat('x', 41), $message);
    }

    public function testAnIntegerKeyIsNamedToo(): void
    {
        self::assertStringContainsString('"3"', InputContradictsValidationException::nonTextualField(3)->getMessage());
    }
}
