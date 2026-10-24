<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Dto;

use App\Mcp\Connection\Application\AuthorizationRequestValidator;
use App\Mcp\Connection\Application\ConsentDecision;
use App\Mcp\Connection\Presentation\Dto\ConsentRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Le corps de `POST /api/oauth/consent` (#647) : ses bornes, l'exigence
 * d'`approved`, et sa conversion en commande de l'application, champ pour
 * champ — un refus quand `approved` manque.
 */
final class ConsentRequestTest extends TestCase
{
    public function testItBecomesTheDecisionFieldForField(): void
    {
        $request = new ConsentRequest('code', 'client', 'http://localhost/cb', 'nominative:read', 's', 'challenge', 'S256', 'https://api.example.test/api/mcp', true);

        self::assertEquals(
            new ConsentDecision('code', 'client', 'http://localhost/cb', 'nominative:read', 's', 'challenge', 'S256', 'https://api.example.test/api/mcp', true),
            $request->toDecision(),
        );
        self::assertFalse((new ConsentRequest(approved: false))->toDecision()->approved);
    }

    /** Sans validation, une décision muette vaut refus : jamais un accord par défaut. */
    public function testAMissingApprovalBecomesADenial(): void
    {
        self::assertFalse((new ConsentRequest())->toDecision()->approved);
    }

    public function testApprovedIsRequired(): void
    {
        $violations = $this->validator()->validate(new ConsentRequest());

        self::assertCount(1, $violations);
        self::assertSame('approved', $violations[0]?->getPropertyPath());
    }

    /** `state` est borné en octets, l'unité du validateur d'autorisation. */
    public function testTheStateIsBoundedInBytes(): void
    {
        $atTheBound = str_repeat('é', intdiv(AuthorizationRequestValidator::STATE_MAX_LENGTH, 2));
        $pastTheBound = $atTheBound.'a';

        self::assertCount(0, $this->validator()->validate(new ConsentRequest(state: $atTheBound, approved: true)));
        $violations = $this->validator()->validate(new ConsentRequest(state: $pastTheBound, approved: true));
        self::assertCount(1, $violations);
        self::assertSame('state', $violations[0]?->getPropertyPath());
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
