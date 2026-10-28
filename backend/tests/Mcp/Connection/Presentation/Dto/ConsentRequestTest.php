<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Presentation\Dto;

use App\Mcp\Connection\Application\AuthorizationRequestValidator;
use App\Mcp\Connection\Application\ConsentDecision;
use App\Mcp\Connection\Presentation\Dto\ConsentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Le corps de `POST /api/oauth/consent` (#647) : ses bornes, l'exigence
 * d'`approved`, et sa conversion en commande de l'application, champ pour
 * champ — un refus quand `approved` manque.
 */
final class ConsentRequestTest extends TestCase
{
    private const string ACCOUNT_ID = '01a181e2-9a50-7567-9420-a8142a191787';

    public function testItBecomesTheDecisionFieldForField(): void
    {
        $request = new ConsentRequest('code', 'client', 'http://localhost/cb', 'nominative:read', 's', 'challenge', 'S256', 'https://api.example.test/api/mcp', true, self::ACCOUNT_ID);

        self::assertEquals(
            new ConsentDecision('code', 'client', 'http://localhost/cb', 'nominative:read', 's', 'challenge', 'S256', 'https://api.example.test/api/mcp', true, Uuid::fromString(self::ACCOUNT_ID)),
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
        $violations = $this->validator()->validate(new ConsentRequest(accountId: self::ACCOUNT_ID));

        self::assertCount(1, $violations);
        self::assertSame('approved', $violations[0]?->getPropertyPath());
    }

    /** Le compte montré est exigé, et doit être un UUID (#650, F5) : absent, vide ou autre chose, un refus de validation. */
    #[DataProvider('provideAnUnusableShownAccount')]
    public function testTheShownAccountIsRequiredAndMustBeAUuid(?string $accountId): void
    {
        $violations = $this->validator()->validate(new ConsentRequest(approved: true, accountId: $accountId));

        self::assertGreaterThanOrEqual(1, \count($violations));
        self::assertSame('accountId', $violations[0]?->getPropertyPath());
    }

    /** @return iterable<string, array{?string}> */
    public static function provideAnUnusableShownAccount(): iterable
    {
        yield 'absent' => [null];
        yield 'vide' => [''];
        yield 'un identifiant de connexion' => ['trusted'];
        yield 'un UUID tronqué' => ['01a181e2-9a50-7567-9420'];
    }

    /** Sans validation, un compte montré illisible ne devient jamais un UUID : `null`, que le service refuse. */
    public function testAnUnusableShownAccountBecomesNoAccountAtAll(): void
    {
        self::assertNull((new ConsentRequest(approved: true))->toDecision()->shownAccountId);
        self::assertNull((new ConsentRequest(approved: true, accountId: 'trusted'))->toDecision()->shownAccountId);
    }

    /** `state` est borné en octets, l'unité du validateur d'autorisation. */
    public function testTheStateIsBoundedInBytes(): void
    {
        $atTheBound = str_repeat('é', intdiv(AuthorizationRequestValidator::STATE_MAX_LENGTH, 2));
        $pastTheBound = $atTheBound.'a';

        self::assertCount(0, $this->validator()->validate(new ConsentRequest(state: $atTheBound, approved: true, accountId: self::ACCOUNT_ID)));
        $violations = $this->validator()->validate(new ConsentRequest(state: $pastTheBound, approved: true, accountId: self::ACCOUNT_ID));
        self::assertCount(1, $violations);
        self::assertSame('state', $violations[0]?->getPropertyPath());
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
