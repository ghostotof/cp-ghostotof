<?php

declare(strict_types=1);

namespace App\Tests\Portfolio\Watch\Infrastructure\Validator;

use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Service\ReleaseCycleSourceInterface;
use App\Portfolio\Watch\Infrastructure\Validator\WatchedProductSlugExists;
use App\Portfolio\Watch\Infrastructure\Validator\WatchedProductSlugExistsValidator;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Log\NullLogger;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<WatchedProductSlugExistsValidator>
 */
final class WatchedProductSlugExistsValidatorTest extends ConstraintValidatorTestCase
{
    private ReleaseCycleSourceInterface&Stub $releaseCycleSource;

    protected function setUp(): void
    {
        // Construit avant parent::setUp(), qui appelle createValidator().
        $this->releaseCycleSource = self::createStub(ReleaseCycleSourceInterface::class);

        parent::setUp();
    }

    protected function createValidator(): ConstraintValidatorInterface
    {
        return new WatchedProductSlugExistsValidator($this->releaseCycleSource, new NullLogger());
    }

    public function testAKnownSlugPasses(): void
    {
        $this->releaseCycleSource->method('supportsProduct')->willReturn(true);

        $this->validator->validate('php', new WatchedProductSlugExists());

        $this->assertNoViolation();
    }

    public function testAnUnknownSlugIsReportedOnTheField(): void
    {
        $this->releaseCycleSource->method('supportsProduct')->willReturn(false);

        $this->validator->validate('phpp', new WatchedProductSlugExists());

        $this->buildViolation('Le produit « {{ slug }} » est inconnu du catalogue endoflife.date.')
            ->setParameter('{{ slug }}', 'phpp')
            ->assertRaised();
    }

    /**
     * Le test qui porte la décision D10 : le fournisseur est injoignable, donc
     * on ne sait rien — et on laisse passer. Une panne chez un tiers ne doit
     * jamais empêcher d'administrer son propre site, d'autant qu'un slug fautif
     * se limiterait à une ligne affichée « inconnue », réparable à tout moment.
     */
    public function testAnUnreachableSourceDoesNotBlockTheSubmission(): void
    {
        $this->releaseCycleSource->method('supportsProduct')->willThrowException(
            ReleaseCycleSourceUnavailableException::forUnexpectedStatus('php', 503),
        );

        $this->validator->validate('php', new WatchedProductSlugExists());

        $this->assertNoViolation();
    }

    /**
     * Un slug vide relève de NotBlank : le signaler une seconde fois n'aiderait
     * personne, et surtout n'appelons pas un tiers pour une valeur qu'on sait
     * déjà invalide.
     */
    public function testAnEmptySlugIsLeftToTheOtherConstraints(): void
    {
        $this->releaseCycleSource->method('supportsProduct')->willReturnCallback(
            static fn (): never => throw new \LogicException(
                'Le fournisseur ne doit pas être interrogé pour une valeur déjà connue comme invalide.',
            ),
        );

        $this->validator->validate('', new WatchedProductSlugExists());

        $this->assertNoViolation();
    }
}
