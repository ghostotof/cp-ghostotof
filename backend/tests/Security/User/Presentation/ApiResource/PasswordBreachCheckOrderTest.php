<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Presentation\ApiResource;

use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Presentation\ApiResource\AccountPasswordSetupResource;
use App\Security\User\Presentation\ApiResource\BackofficeUserPasswordResource;
use App\Tests\Support\TestCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Validator\Constraints\NotCompromisedPasswordValidator;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Revue de #386 : les trois contraintes du mot de passe étaient toutes
 * évaluées. Un mot de passe déjà refusé pour sa longueur partait quand même
 * vers api.pwnedpasswords.com — y compris sur la définition par lien, que
 * tout anonyme peut appeler sans jeton valide, puisque la validation du DTO
 * précède la lecture du jeton.
 *
 * Un vrai validateur, des attributs aux contraintes, dont seul le client HTTP
 * de haveibeenpwned est remplacé : le test compte les appels sortants. En
 * environnement de test, la vérification est désactivée (validator.yaml),
 * d'où l'absence de kernel ici.
 */
final class PasswordBreachCheckOrderTest extends TestCase
{
    private int $breachLookups = 0;

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            ++$this->breachLookups;

            return new MockResponse('');
        });

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(new ConstraintValidatorFactory([
                NotCompromisedPasswordValidator::class => new NotCompromisedPasswordValidator($client),
            ]))
            ->getValidator();
    }

    /** @return iterable<string, array{object}> */
    public static function refusedPasswords(): iterable
    {
        $tooShort = str_repeat('a', CpgUser::MIN_PASSWORD_LENGTH - 1);
        $tooLong = str_repeat('é', intdiv(CpgUser::MAX_PASSWORD_LENGTH, 2) + 1);

        yield 'backoffice, trop court' => [new BackofficeUserPasswordResource($tooShort)];
        yield 'backoffice, trop long' => [new BackofficeUserPasswordResource($tooLong)];
        yield 'backoffice, vide' => [new BackofficeUserPasswordResource('')];
        yield 'définition par lien, trop court' => [self::passwordSetup($tooShort)];
        yield 'définition par lien, trop long' => [self::passwordSetup($tooLong)];
        yield 'définition par lien, vide' => [self::passwordSetup('')];
    }

    #[DataProvider('refusedPasswords')]
    public function testARefusedPasswordIsNeverSentToTheBreachService(object $resource): void
    {
        $violations = $this->validator->validate($resource);

        self::assertGreaterThan(0, $violations->count());
        self::assertSame(0, $this->breachLookups);
    }

    /**
     * Le pendant : un mot de passe de longueur valide est bien vérifié. Sans
     * lui, un validateur qui n'appelle jamais le service ferait passer le test
     * précédent.
     *
     * @return iterable<string, array{object}>
     */
    public static function acceptablePasswords(): iterable
    {
        yield 'backoffice' => [new BackofficeUserPasswordResource(TestCredentials::plainPassword())];
        yield 'définition par lien' => [self::passwordSetup(TestCredentials::plainPassword())];
    }

    #[DataProvider('acceptablePasswords')]
    public function testAnAcceptablePasswordIsCheckedOnce(object $resource): void
    {
        $this->validator->validate($resource);

        self::assertSame(1, $this->breachLookups);
    }

    /**
     * Revue de #386 : la borne haute se compte en octets, le message par
     * défaut de Length parle de caractères. 2 049 « é » se voyaient répondre
     * « 4096 characters or less ».
     */
    public function testTheUpperBoundMessageSpeaksOfBytes(): void
    {
        $violations = $this->validator->validate(new BackofficeUserPasswordResource(str_repeat('é', intdiv(CpgUser::MAX_PASSWORD_LENGTH, 2) + 1)));

        self::assertCount(1, $violations);
        self::assertStringContainsString('4096 bytes', (string) $violations->get(0)->getMessage());
    }

    private static function passwordSetup(string $password): AccountPasswordSetupResource
    {
        $resource = new AccountPasswordSetupResource();
        $resource->token = str_repeat('a', 64);
        $resource->password = $password;

        return $resource;
    }
}
