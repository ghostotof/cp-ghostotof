<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Domain\Exception;

use App\Security\User\Domain\Exception\UnusablePasswordSetupLinkException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #362 : le `detail` de la réponse est le message de l'exception. Les
 * constructeurs nommés documentent le motif pour qui lit le code, mais ne
 * doivent pas le faire fuir : un message propre à l'un d'eux redirait à
 * l'appelant ce que la fusion des statuts a cessé de dire.
 */
final class UnusablePasswordSetupLinkExceptionTest extends TestCase
{
    public function testEveryReasonCarriesTheSameMessage(): void
    {
        self::assertSame(
            UnusablePasswordSetupLinkException::unknown()->getMessage(),
            UnusablePasswordSetupLinkException::expiredOrAlreadyUsed()->getMessage(),
        );
    }

    public function testTheMessageNamesNoReason(): void
    {
        $message = UnusablePasswordSetupLinkException::unknown()->getMessage();

        // Ni « expiré », ni « utilisé », ni « inconnu » : le message ne
        // choisit aucun des motifs qu'il recouvre.
        self::assertDoesNotMatchRegularExpression('/expir|utilis|inconnu|used|unknown/i', $message);
    }
}
