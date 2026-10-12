<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Shared\Infrastructure\Doctrine;

use App\Portfolio\Shared\Domain\Exception\TranslationGroupAcrossSeveralScopesException;
use App\Portfolio\Shared\Domain\Exception\TranslationGroupHasSeveralPositionsException;
use App\Portfolio\Shared\Infrastructure\Doctrine\TranslationGroupRefusal;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\TestCase;

/**
 * Issue #506 : la correspondance entre les SQLSTATE du trigger différé des
 * groupes de traduction et le nom de leur invariant, sortie du verrou d'ordre
 * qui la portait. Sans base : le SQLSTATE suffit à reconnaître le refus, le
 * message n'est jamais lu, seulement repris.
 */
final class TranslationGroupRefusalTest extends TestCase
{
    /** Un message de la forme de celui du trigger : il nomme la table et le groupe. */
    private const string DATABASE_MESSAGE = 'contribution : le groupe de traduction 0199e0c4-0000-7000-8000-000000000000 porte plusieurs positions';

    public function testAGroupOnSeveralPositionsIsNamedAfterItsInvariant(): void
    {
        $refusal = $this->refusal(TranslationGroupRefusal::ON_SEVERAL_POSITIONS);

        $named = TranslationGroupRefusal::nameOf($refusal);

        self::assertInstanceOf(TranslationGroupHasSeveralPositionsException::class, $named);
        self::assertSame($refusal, $named->getPrevious(), 'Le refus de la base n\'est plus la cause de l\'exception nommée.');
        self::assertStringContainsString(self::DATABASE_MESSAGE, $named->getMessage(), 'Le message de la base, qui nomme la table et le groupe, a été perdu.');
    }

    public function testAGroupAcrossSeveralScopesIsNamedAfterItsInvariant(): void
    {
        $refusal = $this->refusal(TranslationGroupRefusal::ACROSS_SEVERAL_SCOPES);

        $named = TranslationGroupRefusal::nameOf($refusal);

        self::assertInstanceOf(TranslationGroupAcrossSeveralScopesException::class, $named);
        self::assertSame($refusal, $named->getPrevious(), 'Le refus de la base n\'est plus la cause de l\'exception nommée.');
        self::assertStringContainsString(self::DATABASE_MESSAGE, $named->getMessage(), 'Le message de la base, qui nomme la table et le groupe, a été perdu.');
    }

    /**
     * Tout autre refus — une autre violation d'intégrité de la même classe 23,
     * ou un refus sans SQLSTATE — n'est pas nommé : l'appelant le relance tel
     * quel.
     */
    public function testAnyOtherRefusalIsNotNamed(): void
    {
        self::assertNull(TranslationGroupRefusal::nameOf($this->refusal('23505')));
        self::assertNull(TranslationGroupRefusal::nameOf($this->refusal(null)));
    }

    private function refusal(?string $sqlState): DriverException
    {
        $driverException = new class(self::DATABASE_MESSAGE, $sqlState) extends AbstractException {
        };

        return new DriverException($driverException, null);
    }
}
