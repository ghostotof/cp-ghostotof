<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\LoginFailureCause;
use App\Security\Authentication\Infrastructure\Security\SubmittedPasswordTooLongException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationServiceException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * Le classement d'un échec de login, écrit une fois pour le compensateur de
 * temps et le journal d'audit (issue #588) : chacun tenait sa propre liste des
 * échecs survenus sans compte chargé, et un refus ajouté à l'une seulement
 * aurait chargé le compte — une requête SQL pour un identifiant existant
 * seulement, donc un écart de temps.
 */
final class LoginFailureCauseTest extends TestCase
{
    #[DataProvider('failures')]
    public function testEachFailureIsClassified(AuthenticationException $exception, LoginFailureCause $expected): void
    {
        self::assertSame($expected, LoginFailureCause::of($exception));
    }

    /** @return iterable<string, array{AuthenticationException, LoginFailureCause}> */
    public static function failures(): iterable
    {
        yield 'throttling' => [new TooManyLoginAttemptsAuthenticationException(), LoginFailureCause::Throttled];
        // AuthenticatorManager masque l'UserNotFoundException derrière une
        // BadCredentialsException dès que `expose_security_errors` n'est pas
        // `all`, en la gardant en `previous`.
        yield 'identifiant inconnu, masqué' => [new BadCredentialsException('Bad credentials.', 0, new UserNotFoundException()), LoginFailureCause::UnknownIdentifier];
        yield 'identifiant inconnu, nu' => [new UserNotFoundException(), LoginFailureCause::UnknownIdentifier];
        yield 'mot de passe trop long' => [new SubmittedPasswordTooLongException(), LoginFailureCause::OverlongPassword];
        yield 'mot de passe faux' => [new BadCredentialsException('The presented password is invalid.'), LoginFailureCause::Other];
        yield 'panne du chargeur' => [new AuthenticationServiceException('Base injoignable.'), LoginFailureCause::Other];
    }

    /**
     * Seul un échec « autre » peut laisser un compte chargé dans le passeport :
     * dans les trois autres cas, `Passport::getUser()` lancerait (ou
     * relancerait) le chargement.
     */
    public function testOnlyAnOrdinaryFailureMayHaveLoadedTheAccount(): void
    {
        foreach (LoginFailureCause::cases() as $cause) {
            self::assertSame(LoginFailureCause::Other !== $cause, $cause->leftNoAccountLoaded(), $cause->name);
        }
    }
}
