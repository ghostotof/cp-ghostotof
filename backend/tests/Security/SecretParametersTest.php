<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Ai\Assistant\Infrastructure\Http\ReplayRefusingHttpClient;
use App\Security\Authentication\Infrastructure\Http\AuthCookieFactory;
use App\Security\Authentication\Infrastructure\Http\CsrfCookieTokenSigner;
use App\Security\User\Application\PasswordSetupService;
use App\Security\User\Application\PasswordSetupServiceInterface;
use App\Security\User\Infrastructure\Log\PasswordOptionRedactor;
use App\Tests\Security\Fixtures\SecretParametersFixture;
use App\Tests\Security\Fixtures\SecretParametersFixtureInterface;
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
 * **Ce que l'attribut protège, et ce qu'il ne protège pas.** En production,
 * `zend.exception_ignore_args = On` retire déjà les arguments des traces : le
 * risque est celui du dev et du test, traces affichées (le corps JSON d'erreur
 * en debug), `ErrorDetailsStamp` de Messenger, journaux de test collés dans un
 * ticket ou une session d'assistant. L'attribut, lui, vaut quelle que soit la
 * configuration du moteur, pour `getTrace()` comme pour `debug_backtrace()`.
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
 * `App\…` le dit. Sur les méthodes des classes de `src/` et de leurs
 * interfaces et classes mères `App\…` ; tout `src/`, et non les seuls
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
 * filtre de type l'écarte, ceux de `src/` sont dans HIDDEN_BY_HAND. Les
 * enums, les closures, les classes anonymes et les fonctions hors classe, que
 * {@see DeclaredClasses} ne recense pas.
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
    ];

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
     * @return list<ReflectionParameter>
     */
    private function srcParameters(): array
    {
        return SensitiveParameters::named(DeclaredClasses::all(\dirname(__DIR__, 2).'/src'), self::SECRET_NAME);
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
