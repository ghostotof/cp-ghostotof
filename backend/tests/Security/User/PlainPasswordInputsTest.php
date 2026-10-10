<?php

declare(strict_types=1);

namespace App\Tests\Security\User;

use ApiPlatform\Metadata\ApiResource;
use App\Security\Authentication\Infrastructure\Security\FailedLoginTimingEqualizer;
use App\Security\User\Application\CpgUserAdministrator;
use App\Security\User\Application\CpgUserAdministratorInterface;
use App\Security\User\Application\CpgUserRegistrar;
use App\Security\User\Application\CpgUserRegistrarInterface;
use App\Security\User\Application\PasswordSetupService;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Infrastructure\ApiPlatform\AccountPasswordSetupProcessor;
use App\Security\User\Infrastructure\ApiPlatform\BackofficeUserPasswordProcessor;
use App\Security\User\Presentation\ApiResource\AccountPasswordSetupResource;
use App\Security\User\Presentation\ApiResource\BackofficeUserPasswordResource;
use App\Security\User\Presentation\Command\CreateCpgUserCommand;
use App\Tests\Security\User\Fixtures\PlainPasswordFieldsFixture;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\PhpSources;
use App\Tests\Support\PlainPasswordInputs;
use App\Tests\Support\SensitiveParameters;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Toute entrée de mot de passe en clair passe par
 * {@see \App\Security\User\Presentation\Validator\PlainPasswordLength} (issue
 * #411, suite de #386).
 *
 * Depuis #386, les trois voies qui reçoivent un mot de passe en clair le
 * valident par la même règle — minimum en caractères, maximum en octets, la
 * borne du hasher. Rien n'obligeait une quatrième à le faire : un DTO qui
 * recopierait `Assert\Length(max: 4096)` en caractères referait le 500 corrigé
 * par #386 ({@see \Symfony\Component\PasswordHasher\Exception\InvalidPasswordException}
 * du hasher, non mappée). Ce test est cette
 * obligation.
 *
 * **Pourquoi une garde, et pas un Value Object `PlainPassword`** (arbitrage
 * de l'issue). Le VO aurait un vrai mérite : un cas d'usage qui ne reçoit
 * qu'un `PlainPassword` ne peut plus atteindre le hasher avec une chaîne trop
 * longue, et PHPStan le vérifierait. Il est écarté pour quatre raisons :
 *  - **il ne garde que la longueur.** Le contrat d'une saisie de mot de passe
 *    est la séquence `NotBlank → PlainPasswordLength → NotCompromisedPassword`,
 *    dans cet ordre
 *    ({@see \App\Tests\Security\User\Presentation\ApiResource\PasswordBreachCheckOrderTest}) ; le contrôle de fuite est un
 *    appel réseau, il n'a rien à faire dans un objet du domaine. Un DTO qui
 *    l'oublierait passerait le VO sans bruit : il faudrait cette garde quand
 *    même ;
 *  - **la règle serait écrite deux fois.** La validation doit rester celle
 *    du Validator, qui rend un 422 avec le champ en cause et le code de la
 *    borne que la CLI traduit ; le VO en referait une copie, ou exigerait de
 *    réécrire PlainPasswordLength autour de lui — et c'est précisément la
 *    divergence entre deux copies que #386 a corrigée. Le cas de
 *    {@see \App\Portfolio\Experience\Domain\ValueObject\TechnologyName} est différent : la valeur y est *transformée* (rognée)
 *    avant le contrôle d'unicité, ce qu'aucune contrainte ne fait ; ici elle
 *    n'est que bornée ;
 *  - **le coût est sans rapport avec le risque** (sévérité info) : trois
 *    signatures, trois implémentations, trois appelants et une cinquantaine
 *    d'appels de tests qui créent un compte, une exception de domaine de plus
 *    avec son mapping et son niveau de journal, et un objet qui contient un
 *    secret à protéger de la sérialisation, de `var_dump` et de `var_export` ;
 *  - **le bénéfice qu'il apportait gratuitement**, cacher la valeur des traces
 *    d'exception, s'obtient par `#[SensitiveParameter]`, que ce test exige
 *    désormais sur chaque paramètre de mot de passe.
 * Le jour où les cas d'usage qui hachent se multiplient, le VO redevient le
 * bon outil : l'inventaire des classes qui hachent ci-dessous dira quand.
 *
 * **Ce que la garde voit**, en trois recensements qui se recoupent
 * (PlainPasswordInputs) :
 *  1. chaque **champ** de `src/` dont le nom dit « mot de passe » et qui peut
 *     porter une chaîne porte la séquence commune, seule ;
 *  2. chaque **paramètre** de ce nom porte `#[SensitiveParameter]` ;
 *  3. les **classes qui hachent** (celles qui nomment un type du composant
 *     PasswordHasher) sont déclarées, chacune avec la méthode d'interface par
 *     laquelle le mot de passe lui arrive ; chaque méthode n'a pour appelants
 *     que des **voies d'entrée** déclarées ; chaque voie valide ce qu'elle
 *     passe — une ressource API Platform par un champ du recensement 1, la
 *     CLI par les deux contraintes qu'elle nomme.
 * Le 3 ferme le trou du 1 : un champ nommé `$secret` qui alimenterait
 * `changePassword()` échappe au recensement par nom, pas à celui des appelants.
 *
 * **Limites assumées.** Un hasher obtenu sans nommer son type (service
 * injecté par son identifiant) n'est pas vu ; un cas d'usage qui hacherait un
 * mot de passe reçu sous un nom sans « pass » non plus, mais il nommerait le
 * hasher et tomberait dans le 3. La connexion (`json_login`) n'est pas une
 * entrée de `src/` : Symfony y refuse lui-même un mot de passe au-delà de la
 * borne du hasher.
 */
final class PlainPasswordInputsTest extends TestCase
{
    /**
     * Champs dont le nom dit « mot de passe » sans être une saisie.
     *
     * @var array<string, string> champ => justification
     */
    private const array NOT_AN_INPUT = [
        CpgUser::class.'::$password' => 'le hash, colonne Doctrine écrite par setPassword(), jamais lue d\'une requête',
    ];

    /**
     * Les classes de src/ qui nomment le hasher, et la méthode d'interface par
     * laquelle leur arrive le mot de passe qu'elles hachent.
     *
     * @var array<class-string, ?string> classe => `Interface::méthode`, null si elle ne hache aucune saisie
     */
    private const array HASHERS = [
        CpgUserAdministrator::class => CpgUserAdministratorInterface::class.'::changePassword',
        CpgUserRegistrar::class => CpgUserRegistrarInterface::class.'::register',
        PasswordSetupService::class => PasswordSetupServiceInterface::class.'::complete',
        // Hache une constante, pour égaliser la durée d'un échec de connexion
        // (audit A10) : le mot de passe soumis n'est jamais lu.
        FailedLoginTimingEqualizer::class => null,
    ];

    /**
     * Chaque méthode qui reçoit un mot de passe en clair, et ses seuls
     * appelants de src/.
     *
     * @var array<string, list<class-string>>
     */
    private const array ENTRY_POINTS = [
        CpgUserAdministratorInterface::class.'::changePassword' => [BackofficeUserPasswordProcessor::class],
        CpgUserRegistrarInterface::class.'::register' => [CreateCpgUserCommand::class],
        PasswordSetupServiceInterface::class.'::complete' => [AccountPasswordSetupProcessor::class],
    ];

    /**
     * Ce qui valide le mot de passe que chaque voie d'entrée transmet.
     *
     * @var array<class-string, ?class-string> voie => ressource API Platform dont elle est le processor, null pour la CLI
     */
    private const array VALIDATED_BY = [
        AccountPasswordSetupProcessor::class => AccountPasswordSetupResource::class,
        BackofficeUserPasswordProcessor::class => BackofficeUserPasswordResource::class,
        // La CLI valide à la main, sans DTO : PlainPasswordLength puis
        // NotCompromisedPassword (fermé, sans skipOnError). Le comportement est
        // épinglé par App\Tests\Security\User\Presentation\Command\CreateCpgUserCommandTest
        // (bornes en caractères et en octets, UTF-8 invalide) et par
        // App\Tests\Security\User\Presentation\Command\CreateCpgUserCommandCompromisedPasswordTest.
        CreateCpgUserCommand::class => null,
    ];

    public function testEveryPlainPasswordFieldOfSrcGoesThroughTheSharedSequence(): void
    {
        $defects = [];
        foreach ($this->srcFields() as $field) {
            $defect = PlainPasswordInputs::defectOf($field);
            if (null !== $defect) {
                $defects[] = $defect;
            }
        }

        self::assertSame([], $defects, 'Un champ de mot de passe en clair passe par Assert\Sequentially([NotBlank, PlainPasswordLength, NotCompromisedPassword]), seule (issue #411) ; si ce n\'est pas une saisie, justifiez-le dans NOT_AN_INPUT.');
    }

    public function testEveryNotAnInputEntryStillNamesAField(): void
    {
        $labels = array_map(PlainPasswordInputs::label(...), PlainPasswordInputs::fields($this->srcClasses()));

        self::assertSame([], array_values(array_diff(array_keys(self::NOT_AN_INPUT), $labels)), 'Une justification qui ne vise plus aucun champ se retire.');
    }

    public function testEveryPlainPasswordParameterOfSrcIsHiddenFromStackTraces(): void
    {
        self::assertSame([], SensitiveParameters::exposed(PlainPasswordInputs::parameters($this->srcClasses())), 'Un paramètre qui reçoit un mot de passe en clair porte #[SensitiveParameter] : sans lui, la valeur figure dans les arguments de toute trace d\'exception qui le traverse.');
    }

    public function testOnlyTheDeclaredClassesHashAPassword(): void
    {
        $declared = array_keys(self::HASHERS);
        sort($declared);

        self::assertSame($declared, PlainPasswordInputs::hasherUsers($this->srcClasses()), 'Une classe qui nomme le hasher se déclare dans HASHERS, avec la méthode par laquelle le mot de passe lui arrive, puis ses voies d\'entrée dans ENTRY_POINTS.');
    }

    public function testEveryHasherImplementsTheMethodDeclaredForIt(): void
    {
        foreach (self::HASHERS as $hasher => $method) {
            if (null === $method) {
                continue;
            }
            [$interface] = explode('::', $method);
            self::assertTrue(is_subclass_of($hasher, $interface), \sprintf('%s n\'implémente pas %s.', $hasher, $interface));
            self::assertArrayHasKey($method, self::ENTRY_POINTS);
        }
    }

    public function testEveryInterfaceMethodReceivingAPasswordIsDeclaredWithItsEntryPoints(): void
    {
        $methods = [];
        foreach (PlainPasswordInputs::parameters($this->srcClasses()) as $parameter) {
            $owner = $parameter->getDeclaringClass();
            if (null !== $owner && $owner->isInterface()) {
                $methods[] = $owner->getName().'::'.$parameter->getDeclaringFunction()->getName();
            }
        }
        sort($methods);

        $declared = array_keys(self::ENTRY_POINTS);
        sort($declared);

        self::assertSame($declared, array_values(array_unique($methods)), 'Une méthode d\'interface qui reçoit un mot de passe en clair se déclare dans ENTRY_POINTS, avec ses appelants.');
    }

    public function testEachMethodReceivingAPasswordIsCalledOnlyFromItsDeclaredEntryPoints(): void
    {
        foreach (self::ENTRY_POINTS as $method => $entryPoints) {
            [$interface, $name] = explode('::', $method);
            self::assertTrue(interface_exists($interface), $interface);

            self::assertSame($entryPoints, PlainPasswordInputs::callers($this->srcClasses(), $interface, $name), \sprintf('Les appelants de %s sont des voies d\'entrée déclarées, chacune avec ce qui valide le mot de passe dans VALIDATED_BY.', $method));
        }
    }

    public function testEveryEntryPointValidatesThePasswordItPasses(): void
    {
        $entryPoints = array_merge(...array_values(self::ENTRY_POINTS));
        sort($entryPoints);
        $declared = array_keys(self::VALIDATED_BY);
        sort($declared);
        self::assertSame($declared, $entryPoints, 'Chaque voie d\'entrée dit ce qui valide le mot de passe qu\'elle transmet.');

        foreach (self::VALIDATED_BY as $entryPoint => $resource) {
            if (null === $resource) {
                $this->assertNamesBothPasswordConstraints($entryPoint);

                continue;
            }

            self::assertContains($entryPoint, $this->processorsOf($resource), \sprintf('%s n\'est pas le processor de %s.', $entryPoint, $resource));
            self::assertNotSame([], PlainPasswordInputs::fields([$resource]), \sprintf('%s n\'a aucun champ que la garde reconnaisse comme mot de passe : nommez-le comme tel, la séquence commune y est alors exigée.', $resource));
        }
    }

    /**
     * La garde recensée sur un DTO qu'une quatrième voie pourrait écrire : un
     * recensement qui ne trouverait rien rendrait les tests sur src/ verts
     * sans rien garder.
     */
    public function testTheFieldCensusFindsAPasswordWhateverItsSpelling(): void
    {
        self::assertSame([
            PlainPasswordFieldsFixture::class.'::$passwordWithTheSharedSequence',
            PlainPasswordFieldsFixture::class.'::$passwordWithAHandWrittenLength',
            PlainPasswordFieldsFixture::class.'::$passwordInAnotherOrder',
            PlainPasswordFieldsFixture::class.'::$passwordWithAnExtraConstraint',
            PlainPasswordFieldsFixture::class.'::$newPassphrase',
            PlainPasswordFieldsFixture::class.'::$motDePasse',
            PlainPasswordFieldsFixture::class.'::$pwd',
        ], array_map(PlainPasswordInputs::label(...), PlainPasswordInputs::fields([PlainPasswordFieldsFixture::class])));
    }

    public function testTheSharedSequenceIsAccepted(): void
    {
        self::assertNull(PlainPasswordInputs::defectOf(new ReflectionProperty(PlainPasswordFieldsFixture::class, 'passwordWithTheSharedSequence')));
    }

    /**
     * La mutation de l'issue en tête : `Assert\Length(max: 4096)` recopié.
     */
    #[DataProvider('defectiveFields')]
    public function testAFieldThatLeavesTheSharedSequenceIsADefect(string $field, string $expected): void
    {
        $defect = PlainPasswordInputs::defectOf(new ReflectionProperty(PlainPasswordFieldsFixture::class, $field));

        self::assertNotNull($defect);
        self::assertStringContainsString($expected, $defect);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function defectiveFields(): iterable
    {
        yield 'borne recopiée à la main' => ['passwordWithAHandWrittenLength', '[NotBlank, Length]'];
        yield 'séquence dans un autre ordre' => ['passwordInAnotherOrder', '[NotBlank, NotCompromisedPassword, PlainPasswordLength]'];
        yield 'contrainte hors de la séquence' => ['passwordWithAnExtraConstraint', '[Type, Sequentially]'];
        yield 'aucune contrainte' => ['newPassphrase', 'aucune contrainte'];
    }

    public function testTheParameterCensusTellsAHiddenPasswordFromAnExposedOne(): void
    {
        $parameters = PlainPasswordInputs::parameters([PlainPasswordFieldsFixture::class]);

        self::assertSame([
            PlainPasswordFieldsFixture::class.'::change($newPlainPassword)' => false,
            PlainPasswordFieldsFixture::class.'::change($plainPassword)' => true,
        ], array_combine(
            array_map(PlainPasswordInputs::label(...), $parameters),
            array_map(SensitiveParameters::isHiddenFromTraces(...), $parameters),
        ));
    }

    #[DataProvider('hasherSnippets')]
    public function testTheHasherCensusSeesEveryWayOfNamingIt(string $code, bool $expected): void
    {
        self::assertSame($expected, PlainPasswordInputs::namesAPasswordHasher($code));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hasherSnippets(): iterable
    {
        yield 'importé' => ["<?php\nnamespace App;\nuse Symfony\\Component\\PasswordHasher\\Hasher\\UserPasswordHasherInterface;\n", true];
        yield 'importé sous un alias' => ["<?php\nnamespace App;\nuse Symfony\\Component\\PasswordHasher\\PasswordHasherInterface as Hasher;\n", true];
        yield 'écrit en entier' => ["<?php\nnamespace App;\nfunction f(\\Symfony\\Component\\PasswordHasher\\Hasher\\PasswordHasherFactoryInterface \$f): void {}\n", true];
        yield 'cité en commentaire seulement' => ["<?php\nnamespace App;\n// Symfony\\Component\\PasswordHasher\\PasswordHasherInterface\n", false];
        yield 'autre composant' => ["<?php\nnamespace App;\nuse Symfony\\Component\\Security\\Core\\User\\PasswordAuthenticatedUserInterface;\n", false];
    }

    #[DataProvider('callerSnippets')]
    public function testTheCallerCensusSeesACallThroughTheInterface(string $code, bool $expected): void
    {
        self::assertSame($expected, PlainPasswordInputs::callsMethodOf($code, CpgUserAdministratorInterface::class, 'changePassword'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function callerSnippets(): iterable
    {
        $import = "<?php\nnamespace App\\Other;\nuse App\\Security\\User\\Application\\CpgUserAdministratorInterface;\n";

        yield 'importée puis appelée' => [$import."\$this->administrator->changePassword(\$id, \$password);\n", true];
        yield 'casse différente, comme PHP' => [$import."\$this->administrator->CHANGEPASSWORD(\$id, \$password);\n", true];
        yield 'appel nullsafe' => [$import."\$this->administrator?->changePassword(\$id, \$password);\n", true];
        yield 'callable de première classe' => [$import."\$f = \$this->administrator->changePassword(...);\n", true];
        yield 'importée sous un alias' => ["<?php\nnamespace App\\Other;\nuse App\\Security\\User\\Application\\CpgUserAdministratorInterface as Admin;\n\$a->changePassword(\$id, \$p);\n", true];
        yield 'depuis son propre espace de noms, sans import' => ["<?php\nnamespace App\\Security\\User\\Application;\nfinal class X { public function __construct(private CpgUserAdministratorInterface \$a) {} public function f(): void { \$this->a->changePassword(\$i, \$p); } }\n", true];
        yield 'importée sans appel' => [$import."\$this->administrator->delete(\$id, \$user);\n", false];
        yield 'appel sans l\'interface' => ["<?php\nnamespace App\\Other;\n\$this->other->changePassword(\$id, \$password);\n", false];
        yield 'homonyme dans un autre espace de noms' => ["<?php\nnamespace App\\Other;\nfinal class X { public function __construct(private CpgUserAdministratorInterface \$a) {} public function f(): void { \$this->a->changePassword(\$i, \$p); } }\n", false];
        yield 'déclaration de la méthode' => [$import."function changePassword(): void {}\n", false];
    }

    /**
     * @return list<ReflectionProperty>
     */
    private function srcFields(): array
    {
        return array_values(array_filter(
            PlainPasswordInputs::fields($this->srcClasses()),
            static fn (ReflectionProperty $field): bool => !\array_key_exists(PlainPasswordInputs::label($field), self::NOT_AN_INPUT),
        ));
    }

    /**
     * La CLI n'a pas de DTO : ce qui se vérifie ici est qu'elle nomme les deux
     * contraintes, dans son code et non dans un commentaire. Leur effet est
     * épinglé par ses propres tests (cf. VALIDATED_BY).
     *
     * @param class-string $entryPoint
     */
    private function assertNamesBothPasswordConstraints(string $entryPoint): void
    {
        $file = (new ReflectionClass($entryPoint))->getFileName();
        self::assertIsString($file);

        // Le dernier segment : la CLI écrit `Assert\NotCompromisedPassword`.
        $names = array_map(
            static fn (PhpToken $token): string => substr($token->text, (int) strrpos('\\'.$token->text, '\\')),
            PhpSources::significantTokens((string) file_get_contents($file)),
        );

        self::assertContains('PlainPasswordLength', $names, \sprintf('%s ne valide pas la longueur par PlainPasswordLength.', $entryPoint));
        self::assertContains('NotCompromisedPassword', $names, \sprintf('%s ne vérifie pas la fuite par NotCompromisedPassword.', $entryPoint));
    }

    /**
     * @return list<class-string>
     */
    private function srcClasses(): array
    {
        return DeclaredClasses::all(\dirname(__DIR__, 3).'/src');
    }

    /**
     * @param class-string $resource
     *
     * @return list<string> les processors des opérations de la ressource
     */
    private function processorsOf(string $resource): array
    {
        $processors = [];
        foreach ((new ReflectionClass($resource))->getAttributes(ApiResource::class) as $attribute) {
            foreach ($attribute->newInstance()->getOperations() ?? [] as $operation) {
                $processor = $operation->getProcessor();
                if (\is_string($processor)) {
                    $processors[] = $processor;
                }
            }
        }

        return $processors;
    }
}
