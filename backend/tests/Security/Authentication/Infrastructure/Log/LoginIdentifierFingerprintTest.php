<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Log;

use App\Security\Authentication\Infrastructure\Log\LoginIdentifierFingerprint;
use App\Security\Authentication\Infrastructure\Log\MissingLoginFingerprintKeyException;
use App\Tests\Support\TestCredentials;
use PHPUnit\Framework\TestCase;

/**
 * Issue #418 : l'empreinte qui remplace l'identifiant tenté dans le journal
 * de sécurité. Elle doit permettre de corréler les tentatives entre elles —
 * et avec le compteur de `login_throttling` — sans jamais laisser lire, ni
 * retrouver sans la clé, la valeur saisie.
 */
final class LoginIdentifierFingerprintTest extends TestCase
{
    private const string SECRET = 'test-kernel-secret';

    public function testTheSameIdentifierAlwaysGivesTheSameFingerprint(): void
    {
        $fingerprint = new LoginIdentifierFingerprint(self::SECRET);

        self::assertSame($fingerprint->of('jane'), $fingerprint->of('jane'));
    }

    /**
     * Le compteur de `login_throttling`
     * ({@see \Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter}) met
     * l'identifiant en minuscules : `Jane` et `jane` partagent un compteur,
     * donc une empreinte, sinon le `login-throttled` déclenché par l'un ne se
     * relierait plus aux `login-failed` de l'autre.
     */
    public function testTheFingerprintFollowsTheCaseFoldingOfTheThrottlingCounter(): void
    {
        $fingerprint = new LoginIdentifierFingerprint(self::SECRET);

        self::assertSame($fingerprint->of('jane'), $fingerprint->of('JANE'));
        self::assertSame($fingerprint->of('élodie'), $fingerprint->of('ÉLODIE'), 'Minuscules multioctets, comme mb_strtolower du limiteur.');
    }

    /**
     * Hors UTF-8, le limiteur retombe sur strtolower : l'empreinte aussi, et
     * sans erreur — un identifiant tenté peut être n'importe quels octets.
     */
    public function testAnIdentifierThatIsNotUtf8IsFingerprintedLikeTheThrottlingCounterDoes(): void
    {
        $fingerprint = new LoginIdentifierFingerprint(self::SECRET);

        self::assertSame($fingerprint->of("JANE\xFF"), $fingerprint->of("jane\xFF"));
    }

    /**
     * Sans clé, l'empreinte ne serait plus qu'un SHA-256 qu'un dictionnaire
     * renverse sans aucun secret : refus explicite, au calcul.
     */
    public function testAnEmptySecretIsRefusedWhenAFingerprintIsComputed(): void
    {
        $fingerprint = new LoginIdentifierFingerprint('');

        $this->expectException(MissingLoginFingerprintKeyException::class);

        $fingerprint->of('jane');
    }

    /**
     * Issue #455 : l'empreinte est calculée sous une clé dérivée, jamais sous
     * `APP_SECRET` brut (l'ancien calcul, préfixe de contexte dans le message).
     */
    public function testTheFingerprintIsNotAnHmacUnderTheRawSecret(): void
    {
        self::assertNotSame(
            substr(hash_hmac('sha256', 'security-audit/login-identifier:jane', self::SECRET), 0, 6),
            (new LoginIdentifierFingerprint(self::SECRET))->of('jane'),
        );
    }

    public function testTwoIdentifiersGiveTwoFingerprints(): void
    {
        $fingerprint = new LoginIdentifierFingerprint(self::SECRET);

        self::assertNotSame($fingerprint->of('jane'), $fingerprint->of('john'));
    }

    /**
     * Une empreinte à clé (HMAC sur `kernel.secret`), pas un simple
     * condensat : sans la clé, un dictionnaire de mots de passe ne permet pas
     * de retrouver ce qui a été tapé à partir des journaux.
     */
    public function testTheFingerprintDependsOnTheSecret(): void
    {
        self::assertNotSame(
            (new LoginIdentifierFingerprint(self::SECRET))->of('jane'),
            (new LoginIdentifierFingerprint('another-secret'))->of('jane'),
        );
        self::assertNotSame(hash('sha256', 'jane'), (new LoginIdentifierFingerprint(self::SECRET))->of('jane'));
    }

    /**
     * 24 bits, pas plus (relecture sécurité de #418) : une empreinte longue
     * confirmerait hors ligne, à qui détient `APP_SECRET` et les journaux, un
     * mot de passe tapé comme identifiant — un échec suivi d'un
     * `login-succeeded` depuis la même IP dit à qui il appartient. À 24 bits,
     * un dictionnaire de 2^30 mots laisse une soixantaine de candidats ; la
     * corrélation, elle, se lit à IP et fenêtre de 15 min fixées, où une
     * collision fortuite (2^-24 par paire) est négligeable.
     */
    public function testTheFingerprintIsSixLowercaseHexCharactersAndNeverTheIdentifier(): void
    {
        $identifier = TestCredentials::variant('typed-as-identifier');
        $value = (new LoginIdentifierFingerprint(self::SECRET))->of($identifier);

        self::assertMatchesRegularExpression('/^[0-9a-f]{6}\z/', $value);
        self::assertStringNotContainsStringIgnoringCase(substr($identifier, 0, 6), $value);
    }
}
