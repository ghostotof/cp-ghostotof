<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Ai\Assistant\Infrastructure\Http\ReplayRefusingHttpClient;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\Authentication\Infrastructure\Log\LoginIdentifierFingerprint;
use App\Security\Authentication\Infrastructure\Log\SecurityAuditLogger;
use App\Security\User\Application\PasswordSetupService;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;
use App\Security\User\Infrastructure\Doctrine\CpgUserRepository;
use App\Security\User\Infrastructure\Log\PasswordOptionRedactor;
use App\Tests\Security\Fixtures\BeyondClasses\SecretParametersEnumFixture;
use App\Tests\Security\Fixtures\BeyondClasses\SecretParametersOrphanInterfaceFixture;
use App\Tests\Security\Fixtures\BeyondClasses\SecretParametersOrphanTraitFixture;
use App\Tests\Security\Fixtures\SecretParametersFixture;
use App\Tests\Security\Fixtures\SecretParametersFixtureInterface;
use App\Tests\Security\Fixtures\UsedTrait\IssuesTokensFixture;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\SensitiveParameters;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Tout paramètre de `src/` qui reçoit un jeton ou un secret en clair porte
 * `#[SensitiveParameter]` (issue #414, suite de #411).
 *
 * #411 l'exige des mots de passe ({@see \App\Tests\Security\User\PlainPasswordInputsTest}).
 * Un jeton de définition du mot de passe, un JWT ou un jeton CSRF ne valent
 * pas moins : qui lit le premier active un compte, qui lit le deuxième en
 * prend l'identité jusqu'à son expiration (ADR 0003, aucune révocation). Sans
 * l'attribut, la valeur figure dans les arguments de toute trace d'exception
 * qui traverse la méthode.
 *
 * **Ce que l'attribut protège, et ce qu'il ne protège pas.** En production et,
 * depuis #420, sous PHPUnit ({@see \App\Tests\Security\ExceptionArgumentsHiddenInTestsTest}),
 * `zend.exception_ignore_args = On` retire déjà les arguments des traces : le
 * risque est celui du dev, traces affichées (le corps JSON d'erreur en debug),
 * `ErrorDetailsStamp` de Messenger, journaux collés dans un ticket ou une
 * session d'assistant, et celui de `debug_backtrace()`, que la directive ne
 * touche pas. L'attribut, lui, vaut quelle que soit la configuration du
 * moteur, pour `getTrace()` comme pour `debug_backtrace()`.
 * Il ne cache que les **arguments**, et seulement dans la frame de la méthode
 * qui le déclare : une valeur recopiée dans le message d'une exception, dans
 * une variable locale (qu'un débogueur pas à pas affiche), ou passée ensuite
 * à une méthode du vendor qui ne le déclare pas (`Cookie::create()`, Lexik),
 * reste visible. Posé sur une interface, il est déclaratif : PHP ne lit que
 * celui de l'implémentation appelée — d'où le recensement des
 * implémentations par le prototype, ci-dessous.
 *
 * **Ce que la garde voit.** Le recensement de #411, partagé
 * ({@see SensitiveParameters::named()}), avec un autre nom : un paramètre qui
 * peut porter une chaîne, dont le nom dit « jeton », « JWT », « secret »,
 * « clé », « DSN »… ({@see self::SECRET_NAME}) sans désigner un condensat, ou
 * dont le paramètre de même position dans une interface ou une classe mère
 * `App\…` le dit. Sur les méthodes des classes, interfaces, traits et
 * enums de `src/` ({@see DeclaredClasses::classLikes()}, issue #421 : un enum,
 * une interface sans implémentation ou un trait sans utilisateur n'étaient
 * atteints par aucune classe) et des interfaces et classes mères `App\…` des
 * classes ; tout `src/`, et non les seuls
 * `Security/User` et `Security/Authentication` que l'issue nomme : le résultat
 * est le même aujourd'hui, et une clé passée un jour à un adaptateur de
 * `Ai/` y serait vue.
 *
 * **Ce qu'elle ne voit pas.** Un secret reçu sous un autre nom, sans
 * prototype qui le nomme : ceux de `src/` sont cachés à la main et épinglés
 * par {@see self::HIDDEN_BY_HAND}. La moitié aléatoire du jeton CSRF arrive à
 * `CsrfCookieTokenSigner::signature()` sous le nom `$random`, laissée visible
 * — sans `APP_SECRET`, elle ne permet pas de forger l'autre moitié. Un
 * secret porté par un objet (`TokenInterface`) : la trace n'affiche d'un
 * objet que sa classe. Un secret dans un tableau (`array $options`) : le
 * filtre de type l'écarte, ceux de `src/` sont dans HIDDEN_BY_HAND.
 *
 * **Les closures, les fonctions fléchées, les classes anonymes et les
 * fonctions hors classe** : limite acceptée (issue #421). La réflexion ne les
 * atteint pas sans exécuter le code, et un recensement par jetons serait
 * aveugle au cas qui le motiverait : un rappel reçoit sa valeur sous le nom
 * générique de son contrat (le validateur et le normaliseur de mot de passe de
 * {@see \App\Security\User\Presentation\Command\CreateCpgUserCommand} la
 * reçoivent en `$value`), qu'aucun recensement par nom ne voit ; il devrait en
 * outre réécrire par jetons le filtre de type et l'exclusion des condensats,
 * sans prototype à consulter. Au relevé de #421 (2026-10-10), aucune
 * closure de `src/` n'a de paramètre au nom de secret, et `src/` n'a ni
 * classe anonyme ni fonction hors classe. Une variable capturée par `use`
 * (le `$clearToken` de
 * {@see \App\Security\User\Infrastructure\Messenger\SendAccountInvitationHandler})
 * n'est pas un argument : elle ne figure dans aucune trace.
 */
final class SecretParametersTest extends TestCase
{
    /**
     * Ce qu'un nom de secret contient, quelle que soit la casse : `token`,
     * `clearToken`, `jwt`, `secret`, `apiKey`, `api_key`, `bearer`, `xsrf`,
     * `credentials`, `authorization`, `dsn`, `privateKey`, `signingKey`,
     * `cookie`, `signature`, `nonce`… Jamais `key` seul, qui viserait
     * `ResolvesUriVariables::uriVariableString(…, string $key)`.
     *
     * Large exprès, comme le nom de mot de passe de #411 : un faux négatif ne
     * se voit pas. Aucun faux positif dans `src/` aujourd'hui, donc aucune
     * liste d'exclusions : le premier (un jeton lexical d'un analyseur, par
     * exemple) l'introduira, avec sa justification et le test qui retire une
     * entrée devenue sans objet, sur le modèle de la liste `NOT_AN_INPUT` de
     * {@see \App\Tests\Security\User\PlainPasswordInputsTest}.
     */
    private const string SECRET_NAME = '/token|jwt|secret|api_?key|bearer|[xc]srf|credential|(?:private|signing|access|encryption|hmac)_?key|authori[sz]ation|dsn|cookie|signature|nonce/i';

    /**
     * Les secrets de `src/` que le recensement ne voit pas, cachés à la main.
     *
     * @var array<string, string> `Classe::méthode($paramètre)` => ce qu'il reçoit
     */
    private const array HIDDEN_BY_HAND = [
        AuthCookieFactory::class.'::create($value)' => 'le JWT ou le jeton CSRF de bearer() et xsrf()',
        ReplayRefusingHttpClient::class.'::request($options)' => 'la clé d\'API Scaleway (`auth_bearer`) et le corps : le corpus du CV nominatif',
        PasswordOptionRedactor::class.'::redact($text)' => 'l\'argv d\'app:user:create, `--password=<mot de passe>` compris',
        // Issue #418 : l'identifiant tenté au login peut être un mot de passe
        // tapé dans le mauvais champ.
        SecurityAuditLoggerInterface::class.'::loginFailed($triedIdentifier)' => self::TRIED_IDENTIFIER,
        SecurityAuditLoggerInterface::class.'::loginThrottled($triedIdentifier)' => self::TRIED_IDENTIFIER,
        SecurityAuditLogger::class.'::loginFailed($triedIdentifier)' => self::TRIED_IDENTIFIER,
        SecurityAuditLogger::class.'::loginThrottled($triedIdentifier)' => self::TRIED_IDENTIFIER,
        SecurityAuditLogger::class.'::attemptSubject($triedIdentifier)' => self::TRIED_IDENTIFIER,
        LoginIdentifierFingerprint::class.'::of($identifier)' => self::TRIED_IDENTIFIER,
        CpgUserRepository::class.'::loadUserByIdentifier($identifier)' => self::TRIED_IDENTIFIER.' (chargeur du fournisseur `cpg_users`)',
        CpgUserRepositoryInterface::class.'::findOneByUsername($username)' => self::TRIED_IDENTIFIER.' (appelé par le chargeur)',
        CpgUserRepository::class.'::findOneByUsername($username)' => self::TRIED_IDENTIFIER.' (appelé par le chargeur)',
    ];

    /** Ce que reçoivent les paramètres de l'issue #418. */
    private const string TRIED_IDENTIFIER = 'l\'identifiant tenté au login, possiblement un mot de passe';

    public function testEverySecretParameterOfSrcIsHiddenFromStackTraces(): void
    {
        self::assertSame([], SensitiveParameters::exposed($this->srcParameters()), 'Un paramètre qui reçoit un jeton ou un secret en clair porte #[SensitiveParameter] (issue #414) : sans lui, la valeur figure dans les arguments de toute trace d\'exception qui le traverse. Un nom qui ne désigne pas un secret se renomme, ou s\'écarte par une liste d\'exclusions justifiées, à créer (cf. SECRET_NAME).');
    }

    /**
     * Un chemin cassé, ou un DeclaredClasses qui ne trouverait plus rien,
     * rendrait le test précédent vert à vide.
     */
    public function testTheCensusOfSrcStillFindsTheKnownSecrets(): void
    {
        $labels = array_map(SensitiveParameters::label(...), $this->srcParameters());

        foreach ([
            PasswordSetupServiceInterface::class.'::validate($clearToken)',
            PasswordSetupService::class.'::validate($clearToken)',
            AuthCookieFactory::class.'::bearer($jwt)',
            CsrfCookieTokenSigner::class.'::isValid($token)',
        ] as $known) {
            self::assertContains($known, $labels);
        }
    }

    public function testEverySecretHiddenByHandStaysHidden(): void
    {
        foreach (array_keys(self::HIDDEN_BY_HAND) as $label) {
            self::assertTrue(SensitiveParameters::isHiddenFromTraces($this->parameter($label)), \sprintf('%s reçoit %s : il porte #[SensitiveParameter].', $label, self::HIDDEN_BY_HAND[$label]));
        }
    }

    /**
     * Une entrée que le recensement voit déjà est gardée par le premier test :
     * elle se retire d'ici.
     */
    public function testNoSecretHiddenByHandIsAlreadyCensused(): void
    {
        $labels = array_map(SensitiveParameters::label(...), $this->srcParameters());

        self::assertSame([], array_values(array_intersect(array_keys(self::HIDDEN_BY_HAND), $labels)));
    }

    /**
     * Un recensement qui ne trouverait rien rendrait le test sur src/ vert
     * sans rien garder.
     */
    public function testTheCensusTellsAHiddenSecretFromAnExposedOneWhateverItsSpelling(): void
    {
        $fixture = SecretParametersFixture::class;
        $expected = [
            $fixture.'::issue($jwt)' => false,
            $fixture.'::issue($clearToken)' => true,
            $fixture.'::issue($refreshToken)' => false,
            $fixture.'::issue($clientSecret)' => false,
            $fixture.'::issue($apiKey)' => false,
            $fixture.'::issue($API_KEY)' => false,
            $fixture.'::issue($bearer)' => false,
            $fixture.'::issue($csrf)' => false,
            $fixture.'::sign($xsrf)' => false,
            $fixture.'::sign($credentials)' => false,
            $fixture.'::sign($authorization)' => false,
            $fixture.'::sign($dsn)' => false,
            $fixture.'::sign($privateKey)' => false,
            $fixture.'::sign($signingKey)' => false,
            $fixture.'::sign($hmacKey)' => false,
            $fixture.'::sign($cookie)' => false,
            $fixture.'::sign($signature)' => false,
            $fixture.'::sign($nonce)' => false,
            $fixture.'::digest($unhashedToken)' => false,
            $fixture.'::digest($tokenToHash)' => false,
            $fixture.'::digest($hashSecret)' => false,
            // Le prototype nomme le jeton, l'implémentation l'appelle $value.
            $fixture.'::verify($value)' => false,
            SecretParametersFixtureInterface::class.'::verify($clearToken)' => true,
        ];

        $parameters = SensitiveParameters::named([$fixture], self::SECRET_NAME);
        $actual = array_combine(
            array_map(SensitiveParameters::label(...), $parameters),
            array_map(SensitiveParameters::isHiddenFromTraces(...), $parameters),
        );
        ksort($expected);
        ksort($actual);

        self::assertSame($expected, $actual);
    }

    /**
     * Un enum, une interface sans implémentation et un trait sans utilisateur
     * déclarent des méthodes que la réflexion n'atteint par aucune classe :
     * le recensement de src/ doit les lire eux-mêmes (issue #421).
     */
    public function testTheCensusSeesEnumsAndOrphanInterfacesAndTraits(): void
    {
        self::assertSame([
            SecretParametersEnumFixture::class.'::fromToken($token)',
            SecretParametersOrphanInterfaceFixture::class.'::authenticate($apiKey)',
            SecretParametersOrphanTraitFixture::class.'::decrypt($secret)',
        ], SensitiveParameters::exposed($this->parametersIn(__DIR__.'/Fixtures/BeyondClasses')));
    }

    /**
     * La méthode d'un trait utilisé est signalée une fois, sous la classe qui
     * l'utilise : c'est là que se lit son prototype, une interface de la
     * classe (issue #421).
     */
    public function testAMethodOfAUsedTraitIsCensusedOnce(): void
    {
        self::assertSame([IssuesTokensFixture::class.'::issue($token)'], SensitiveParameters::exposed($this->parametersIn(__DIR__.'/Fixtures/UsedTrait')));
    }

    /**
     * @return list<ReflectionParameter>
     */
    private function srcParameters(): array
    {
        return $this->parametersIn(\dirname(__DIR__, 2).'/src');
    }

    /**
     * Le recensement de la garde, sur src/ comme sur un répertoire fixture.
     *
     * @return list<ReflectionParameter>
     */
    private function parametersIn(string $directory): array
    {
        return SensitiveParameters::named(DeclaredClasses::classLikes($directory), self::SECRET_NAME);
    }

    private function parameter(string $label): ReflectionParameter
    {
        self::assertSame(1, preg_match('/^(?<class>[^:]+)::(?<method>\w+)\(\$(?<parameter>\w+)\)\z/', $label, $parts), $label);

        foreach ((new ReflectionMethod($parts['class'], $parts['method']))->getParameters() as $parameter) {
            if ($parameter->getName() === $parts['parameter']) {
                return $parameter;
            }
        }

        self::fail(\sprintf('%s ne désigne plus aucun paramètre.', $label));
    }
}
