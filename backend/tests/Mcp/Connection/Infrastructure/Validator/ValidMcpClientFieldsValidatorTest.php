<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\Validator;

use App\Mcp\Connection\Domain\Entity\McpClient;
use App\Mcp\Connection\Domain\ValueObject\AllowedClientDomains;
use App\Mcp\Connection\Domain\ValueObject\McpClientId;
use App\Mcp\Connection\Domain\ValueObject\McpClientName;
use App\Mcp\Connection\Domain\ValueObject\RedirectUri;
use App\Mcp\Connection\Infrastructure\Validator\ValidMcpClientFields;
use App\Mcp\Connection\Infrastructure\Validator\ValidMcpClientFieldsValidator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClassConstant;
use stdClass;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ValidMcpClientFieldsValidator>
 */
final class ValidMcpClientFieldsValidatorTest extends ConstraintValidatorTestCase
{
    private const string NONE_MESSAGE = 'Au moins une redirection est requise.';
    private const string TOO_MANY_MESSAGE = 'Dix redirections au plus.';

    protected function createValidator(): ConstraintValidatorInterface
    {
        return new ValidMcpClientFieldsValidator(AllowedClientDomains::fromList(['claude.ai', 'chatgpt.com']));
    }

    /**
     * @param array<array-key, mixed> $redirectUris
     */
    private function input(?string $clientId, string $name, array $redirectUris, bool $withClientId = true): object
    {
        if (!$withClientId) {
            return new class($name, $redirectUris) {
                /** @param array<array-key, mixed> $redirectUris */
                public function __construct(public string $name, public array $redirectUris)
                {
                }
            };
        }

        return new class($clientId, $name, $redirectUris) {
            /** @param array<array-key, mixed> $redirectUris */
            public function __construct(public ?string $clientId, public string $name, public array $redirectUris)
            {
            }
        };
    }

    /**
     * @return list<string>
     */
    private function uris(int $count): array
    {
        return array_map(static fn (int $i): string => \sprintf('https://app.example/cb%d', $i), range(1, $count));
    }

    public function testAValidInputRaisesNothing(): void
    {
        $this->validator->validate($this->input('mon-client', 'Mon client', $this->uris(2)), new ValidMcpClientFields());

        $this->assertNoViolation();
    }

    public function testAClientIdOutsideTheAlphabetIsReportedAtClientId(): void
    {
        $this->validator->validate($this->input('espace interdit', 'Mon client', $this->uris(1)), new ValidMcpClientFields());

        $this->buildViolation('L\'identifiant du client doit compter 1 à 255 caractères parmi A-Z a-z 0-9 . _ : / -.')
            ->atPath('property.path.clientId')
            ->assertRaised();
    }

    public function testTheMetadataUrlOfAnAllowedDomainIsReportedAtClientIdWithAFixedMessage(): void
    {
        $this->validator->validate(
            $this->input('https://claude.ai/oauth/claude-code-client-metadata', 'Mon client', $this->uris(1)),
            new ValidMcpClientFields(),
        );

        // Texte fixe : la valeur saisie ne revient jamais dans la réponse.
        $this->buildViolation('Cet identifiant est celui d\'un client CIMD d\'un domaine vérifié : il ne se pré-enregistre pas.')
            ->atPath('property.path.clientId')
            ->assertRaised();
    }

    public function testTheOtherAllowedDomainIsReportedToo(): void
    {
        $this->validator->validate(
            $this->input('https://chatgpt.com/oauth/client.json', 'Mon client', $this->uris(1)),
            new ValidMcpClientFields(),
        );

        $this->buildViolation('Cet identifiant est celui d\'un client CIMD d\'un domaine vérifié : il ne se pré-enregistre pas.')
            ->atPath('property.path.clientId')
            ->assertRaised();
    }

    /**
     * Ce que {@see \App\Mcp\Connection\Domain\ValueObject\CimdClientId} ne reconnaît pas
     * n'est pas un identifiant CIMD : l'admission ne le confondrait pas avec
     * un client d'un domaine vérifié, rien à refuser ici.
     */
    #[DataProvider('clientIdsThatAreNotAnAllowedCimdIdentifier')]
    public function testAnIdentifierThatIsNotAnAllowedCimdIdentifierRaisesNothing(string $clientId): void
    {
        $this->validator->validate($this->input($clientId, 'Mon client', $this->uris(1)), new ValidMcpClientFields());

        $this->assertNoViolation();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function clientIdsThatAreNotAnAllowedCimdIdentifier(): iterable
    {
        yield 'https URL of a domain that is not allowed' => ['https://client.example.test/metadata.json'];
        yield 'subdomain of an allowed domain (no implicit subdomain)' => ['https://evil.claude.ai/metadata.json'];
        yield 'allowed domain as a suffix of another' => ['https://notclaude.ai/metadata.json'];
        yield 'allowed domain with a port' => ['https://claude.ai:443/metadata.json'];
        yield 'allowed domain with a capitalised host' => ['https://Claude.ai/metadata.json'];
        yield 'allowed domain over http' => ['http://claude.ai/metadata.json'];
        yield 'allowed domain without path' => ['https://claude.ai'];
        yield 'plain identifier' => ['claude-code-dev'];
    }

    public function testAnEmptyNameIsReportedAtName(): void
    {
        $this->validator->validate($this->input('mon-client', '', $this->uris(1)), new ValidMcpClientFields());

        $this->buildViolation('Le nom du client doit compter 1 à 100 caractères en UTF-8, sans caractère de contrôle ni saut de ligne.')
            ->atPath('property.path.name')
            ->assertRaised();
    }

    public function testAnInadmissibleRedirectIsReportedAtItsIndex(): void
    {
        $uris = [...$this->uris(2), 'http://evil.example'];

        $this->validator->validate($this->input('mon-client', 'Mon client', $uris), new ValidMcpClientFields());

        $this->buildViolation('Une redirection en http n\'est admise que sur la boucle locale : localhost, 127.0.0.1 ou [::1].')
            ->atPath('property.path.redirectUris[2]')
            ->assertRaised();
    }

    public function testElevenDistinctRedirectsAreReportedAtRedirectUris(): void
    {
        $this->validator->validate($this->input('mon-client', 'Mon client', $this->uris(11)), new ValidMcpClientFields());

        $this->buildViolation(self::TOO_MANY_MESSAGE)
            ->atPath('property.path.redirectUris')
            ->assertRaised();
    }

    public function testNoRedirectIsReportedAtRedirectUris(): void
    {
        $this->validator->validate($this->input('mon-client', 'Mon client', []), new ValidMcpClientFields());

        $this->buildViolation(self::NONE_MESSAGE)
            ->atPath('property.path.redirectUris')
            ->assertRaised();
    }

    public function testARevisionWithoutClientIdIsNotReportedOnClientId(): void
    {
        $this->validator->validate($this->input(null, 'Mon client', $this->uris(1), false), new ValidMcpClientFields());

        $this->assertNoViolation();
    }

    public function testANullClientIdIsNotReportedOnClientId(): void
    {
        $this->validator->validate($this->input(null, 'Mon client', $this->uris(1)), new ValidMcpClientFields());

        $this->assertNoViolation();
    }

    public function testElevenRedirectsWithTwoDuplicatesCountAsNineDistinctAndAgreeWithTheEntity(): void
    {
        // Onze entrées dont deux répétées à l'identique : neuf distinctes.
        $uris = [...$this->uris(9), $this->uris(9)[0], $this->uris(9)[1]];
        self::assertCount(11, $uris);

        $this->validator->validate($this->input('mon-client', 'Mon client', $uris), new ValidMcpClientFields());
        $this->assertNoViolation();

        // Même règle que le domaine : l'entité accepte exactement cette entrée.
        McpClient::register(
            McpClientId::fromString('mon-client'),
            McpClientName::fromString('Mon client'),
            array_map(RedirectUri::fromString(...), $uris),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $this->addToAssertionCount(1);
    }

    public function testTenDistinctRedirectsWithDuplicatesRaiseNothing(): void
    {
        $uris = [...$this->uris(10), ...$this->uris(10)];

        $this->validator->validate($this->input('mon-client', 'Mon client', $uris), new ValidMcpClientFields());

        $this->assertNoViolation();
    }

    public function testRedirectsDifferingOnlyByWhatTheDomainNormalizesAreRefusedNotCounted(): void
    {
        // Le domaine refuse une redirection non normalisée (il ne la
        // normalise pas) : elle est une violation à son index, jamais une
        // « distincte » de plus. Le validateur suit la même règle.
        $uris = ['https://example.com/cb', 'HTTPS://Example.com/cb'];

        $this->validator->validate($this->input('mon-client', 'Mon client', $uris), new ValidMcpClientFields());

        $this->buildViolation('La redirection n\'est pas écrite sous sa forme normalisée (schéma et hôte en minuscules, caractères encodés, aucun port vide).')
            ->atPath('property.path.redirectUris[1]')
            ->assertRaised();
    }

    public function testANonStringRedirectIsReportedAtItsIndexWithoutTypeError(): void
    {
        $this->validator->validate($this->input('mon-client', 'Mon client', ['https://app.example/cb', 42]), new ValidMcpClientFields());

        $this->buildViolation('Chaque redirection doit être une chaîne de caractères.')
            ->atPath('property.path.redirectUris[1]')
            ->assertRaised();
    }

    public function testAnUnexpectedShapeIsRefusedWithAnUnexpectedValueException(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(new stdClass(), new ValidMcpClientFields());
    }

    public function testRedirectUrisThatAreNotAnArrayAreRefused(): void
    {
        $object = new class {
            public string $name = 'Mon client';
            public string $redirectUris = 'https://app.example/cb';
        };

        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate($object, new ValidMcpClientFields());
    }

    public function testAPrivateNameIsRefusedWithAnUnexpectedValueExceptionNotAnError(): void
    {
        // property_exists() est vrai d'une propriété privée : la lecture
        // directe lèverait une \Error (un 500), pas une exception du validateur.
        $object = new class {
            /** @var list<string> */
            public array $redirectUris = ['https://app.example/cb'];
            private string $name = 'Mon client';

            public function name(): string
            {
                return $this->name;
            }
        };
        self::assertSame('Mon client', $object->name());

        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate($object, new ValidMcpClientFields());
    }

    public function testAProtectedRedirectUrisIsRefusedWithAnUnexpectedValueException(): void
    {
        $object = new class {
            public string $name = 'Mon client';
            /** @var list<string> */
            protected array $redirectUris = ['https://app.example/cb'];

            /** @return list<string> */
            public function redirectUris(): array
            {
                return $this->redirectUris;
            }
        };
        self::assertCount(1, $object->redirectUris());

        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate($object, new ValidMcpClientFields());
    }

    public function testAnUninitializedTypedNameIsRefusedWithAnUnexpectedValueException(): void
    {
        $object = new class {
            public string $name;
            /** @var list<string> */
            public array $redirectUris = ['https://app.example/cb'];
        };

        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate($object, new ValidMcpClientFields());
    }

    public function testAnUninitializedTypedRedirectUrisIsRefusedWithAnUnexpectedValueException(): void
    {
        $object = new class {
            public string $name = 'Mon client';
            /** @var list<string> */
            public array $redirectUris;
        };

        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate($object, new ValidMcpClientFields());
    }

    public function testAnUninitializedTypedClientIdIsRefusedWithAnUnexpectedValueException(): void
    {
        // Présente mais illisible : ce n'est pas une révision (propriété
        // absente), c'est une forme inattendue.
        $object = new class {
            public ?string $clientId;
            public string $name = 'Mon client';
            /** @var list<string> */
            public array $redirectUris = ['https://app.example/cb'];
        };

        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate($object, new ValidMcpClientFields());
    }

    public function testAPrivateClientIdIsRefusedWithAnUnexpectedValueException(): void
    {
        $object = new class {
            public string $name = 'Mon client';
            /** @var list<string> */
            public array $redirectUris = ['https://app.example/cb'];
            private string $clientId = 'mon-client';

            public function clientId(): string
            {
                return $this->clientId;
            }
        };
        self::assertSame('mon-client', $object->clientId());

        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate($object, new ValidMcpClientFields());
    }

    public function testAnAbsentClientIdStaysAcceptedForARevision(): void
    {
        // Garde-fou du correctif : « absente » reste distincte de « illisible ».
        $this->validator->validate($this->input(null, 'Mon client', $this->uris(1), false), new ValidMcpClientFields());

        $this->assertNoViolation();
    }

    /**
     * @param array<array-key, mixed> $redirectUris
     */
    #[DataProvider('redirectsThatAreNotAList')]
    public function testRedirectsThatAreNotAListGiveOneViolationAtRedirectUrisAndNeverQuoteTheKey(array $redirectUris): void
    {
        $this->validator->validate($this->input('mon-client', 'Mon client', $redirectUris), new ValidMcpClientFields());

        // Une seule violation, au chemin du champ, sans la clé de l'appelant.
        $this->buildViolation('Les redirections doivent former une liste.')
            ->atPath('property.path.redirectUris')
            ->assertRaised();
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function redirectsThatAreNotAList(): iterable
    {
        yield 'clé textuelle' => [['evil' => 'https://app.example/cb']];
        yield 'clés non séquentielles' => [[1 => 'https://app.example/cb', 3 => 'https://app.example/cb2']];
        yield 'clé textuelle et valeur invalide' => [['evil' => 'http://evil.example']];
    }

    public function testTheBoundMessageStaysInSyncWithTheDomainConstant(): void
    {
        // Le message dit « Dix » : si la borne du domaine change, ce test
        // rougit et rappelle de revoir le texte.
        self::assertSame(10, new ReflectionClassConstant(McpClient::class, 'MAX_REDIRECT_URIS')->getValue());
    }
}
