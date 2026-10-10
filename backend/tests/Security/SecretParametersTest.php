<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Security\Fixtures\SecretParametersFixture;
use App\Tests\Support\DeclaredClasses;
use App\Tests\Support\SensitiveParameters;
use PHPUnit\Framework\TestCase;
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
 * risque est celui du dev et du test, traces affichées et journaux de test
 * collés dans un ticket ou une session d'assistant. L'attribut, lui, vaut
 * quelle que soit la configuration du moteur. Il ne cache que les
 * **arguments** : une valeur recopiée dans le message d'une exception, ou
 * dans une variable locale qu'un débogueur affiche, reste visible.
 *
 * **Ce que la garde voit.** Le recensement est celui de #411
 * ({@see SensitiveParameters::named()}) avec un autre nom : un paramètre de
 * `src/` ou de ses interfaces `App\…`, qui peut porter une chaîne, dont le nom
 * dit « jeton », « JWT », « secret » ou « clé d'API » ({@see self::SECRET_NAME}),
 * sauf s'il dit aussi « hash ». Tout `src/`, et non les seuls `Security/User`
 * et `Security/Authentication` que l'issue nomme : le résultat est le même
 * aujourd'hui, et une clé d'API passée un jour à un adaptateur de `Ai/`
 * (ADR 0004) y serait vue.
 *
 * **Limites assumées.** Un secret reçu sous un autre nom n'est pas vu : le
 * JWT et le jeton CSRF arrivent à `AuthCookieFactory::create()` sous le nom
 * `$value`, caché à la main ; la moitié aléatoire du jeton CSRF arrive à
 * `CsrfCookieTokenSigner::signature()` sous le nom `$random`, laissée
 * visible — sans `APP_SECRET`, elle ne permet pas de forger l'autre moitié.
 * Les arguments des méthodes du vendor (`Cookie::create()`) restent ceux
 * que le vendor déclare. Un secret porté par un objet (`TokenInterface`) non plus :
 * l'attribut ne cache qu'une valeur, et la trace n'affiche d'un objet que sa
 * classe.
 */
final class SecretParametersTest extends TestCase
{
    /**
     * Ce qu'un nom de secret contient, quelle que soit la casse : `token`,
     * `clearToken`, `jwt`, `secret`, `clientSecret`, `apiKey`, `api_key`.
     * Large exprès, comme le nom de mot de passe de #411 : un faux négatif ne
     * se voit pas. Aucun faux positif aujourd'hui, donc aucune liste
     * d'exclusions : le premier (un jeton lexical d'un analyseur, par
     * exemple) l'introduira, avec sa justification et le test qui retire une
     * entrée devenue sans objet, sur le modèle de `NOT_AN_INPUT` dans
     * PlainPasswordInputsTest.
     */
    private const string SECRET_NAME = '/token|jwt|secret|api_?key/i';

    public function testEverySecretParameterOfSrcIsHiddenFromStackTraces(): void
    {
        $exposed = array_map(
            $this->label(...),
            array_values(array_filter(
                $this->srcParameters(),
                static fn (ReflectionParameter $parameter): bool => !SensitiveParameters::isHiddenFromTraces($parameter),
            )),
        );

        self::assertSame([], $exposed, 'Un paramètre qui reçoit un jeton ou un secret en clair porte #[SensitiveParameter] (issue #414) : sans lui, la valeur figure dans les arguments de toute trace d\'exception qui le traverse. Si ce n\'est pas un secret, écartez-le par une liste d\'exclusions justifiées.');
    }

    /**
     * Un recensement qui ne trouverait rien rendrait le test sur src/ vert
     * sans rien garder.
     */
    public function testTheCensusTellsAHiddenSecretFromAnExposedOneWhateverItsSpelling(): void
    {
        $parameters = SensitiveParameters::named([SecretParametersFixture::class], self::SECRET_NAME);

        self::assertSame([
            'issue($jwt)' => false,
            'issue($clearToken)' => true,
            'issue($refreshToken)' => false,
            'issue($clientSecret)' => false,
            'issue($apiKey)' => false,
        ], array_combine(
            array_map(static fn (ReflectionParameter $parameter): string => $parameter->getDeclaringFunction()->getName().'($'.$parameter->getName().')', $parameters),
            array_map(SensitiveParameters::isHiddenFromTraces(...), $parameters),
        ));
    }

    /**
     * @return list<ReflectionParameter>
     */
    private function srcParameters(): array
    {
        return SensitiveParameters::named(DeclaredClasses::all(\dirname(__DIR__, 2).'/src'), self::SECRET_NAME);
    }

    private function label(ReflectionParameter $parameter): string
    {
        return ($parameter->getDeclaringClass()?->getName() ?? '').'::'.$parameter->getDeclaringFunction()->getName().'($'.$parameter->getName().')';
    }
}
