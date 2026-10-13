<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Infrastructure\Security\OverlongPasswordRefusalListener;
use App\Security\Authentication\Infrastructure\Security\SubmittedPasswordTooLongException;
use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\TestCredentials;
use Closure;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Issue #588 : la borne est celle du hasher, en **octets**
 * (`CpgUser::MAX_PASSWORD_LENGTH`), et le refus a lieu sans que le compte ne
 * soit chargé — c'est ce qui rend son temps indépendant de l'identifiant.
 * Chaque cas passe un chargeur qui compte ses appels : il ne doit jamais
 * tourner ici.
 */
final class OverlongPasswordRefusalListenerTest extends TestCase
{
    private int $loaderCalls = 0;

    public function testASubmissionBeyondTheBoundIsRefusedWithoutLoadingTheAccount(): void
    {
        try {
            (new OverlongPasswordRefusalListener())($this->check(TestCredentials::overlong()));
            self::fail('Une saisie au-delà de la borne du hasher doit être refusée.');
        } catch (SubmittedPasswordTooLongException) {
        }

        self::assertSame(0, $this->loaderCalls, 'Le refus a chargé le compte : son temps dépendrait de l\'existence de l\'identifiant.');
    }

    /**
     * Le refus n'est juste que si la borne du domaine est celle au-delà de
     * laquelle le hasher cesse de dériver : plus haute, l'écart de temps
     * reviendrait entre les deux ; plus basse, un mot de passe valide serait
     * refusé. `CpgUser::MAX_PASSWORD_LENGTH` est un littéral : ce test le lie
     * à la constante de Symfony, qui pourrait changer à une montée de version.
     */
    public function testTheBoundIsTheOneTheHasherStopsDerivingAt(): void
    {
        // Lues par réflexion : écrites en constantes, l'égalité est connue de
        // PHPStan à l'analyse, qui refuse une assertion toujours vraie. Le
        // test n'a de sens qu'à la prochaine montée de Symfony.
        self::assertSame(
            (new ReflectionClassConstant(PasswordHasherInterface::class, 'MAX_PASSWORD_LENGTH'))->getValue(),
            (new ReflectionClassConstant(CpgUser::class, 'MAX_PASSWORD_LENGTH'))->getValue(),
        );
    }

    /**
     * La borne est comptée en octets, l'unité de `strlen()` dans le hasher :
     * 2 049 « é » font 2 049 caractères mais 4 098 octets, et `verify()` les
     * refuserait sans rien dériver.
     */
    public function testTheBoundIsCountedInBytes(): void
    {
        $this->expectException(SubmittedPasswordTooLongException::class);

        (new OverlongPasswordRefusalListener())($this->check(str_repeat('é', intdiv(CpgUser::MAX_PASSWORD_LENGTH, 2) + 1)));
    }

    /**
     * Le pendant multioctet de la borne exacte : 2 048 « é » font 4 096
     * octets, autant qu'un mot de passe posé par `PlainPasswordLength` peut en
     * avoir. Compter en caractères, ou refuser à `>=`, l'écarterait du login.
     */
    public function testAMultibyteSubmissionAtTheBoundGoesOnToTheRealVerification(): void
    {
        (new OverlongPasswordRefusalListener())($this->check(str_repeat('é', intdiv(CpgUser::MAX_PASSWORD_LENGTH, 2))));

        self::assertSame(0, $this->loaderCalls);
    }

    /**
     * Exactement la borne : un mot de passe posé par l'une des trois voies
     * (`PlainPasswordLength`) peut faire jusqu'à cette longueur, il doit
     * pouvoir se connecter.
     */
    public function testASubmissionAtTheBoundGoesOnToTheRealVerification(): void
    {
        $atTheBound = substr(TestCredentials::overlong(), 0, CpgUser::MAX_PASSWORD_LENGTH);
        $event = $this->check($atTheBound);

        (new OverlongPasswordRefusalListener())($event);

        self::assertFalse($event->getPassport()->getBadge(PasswordCredentials::class)?->isResolved());
        self::assertSame(0, $this->loaderCalls);
    }

    /**
     * Le firewall `api` authentifie par JWT : passeport auto-validé, aucun
     * mot de passe. Rien à borner, rien à lire.
     */
    public function testAPassportWithoutPasswordCredentialsIsIgnored(): void
    {
        $event = new CheckPassportEvent(
            self::createStub(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge('jane', $this->loaderThatMustNotRun())),
        );

        (new OverlongPasswordRefusalListener())($event);

        self::assertSame(0, $this->loaderCalls);
    }

    /**
     * Des identifiants déjà vérifiés ont perdu leur valeur
     * (`PasswordCredentials::markResolved()`) : la relire lèverait une
     * LogicException.
     */
    public function testResolvedCredentialsAreIgnored(): void
    {
        $credentials = new PasswordCredentials(TestCredentials::overlong());
        $credentials->markResolved();

        (new OverlongPasswordRefusalListener())(new CheckPassportEvent(
            self::createStub(AuthenticatorInterface::class),
            new Passport(new UserBadge('jane', $this->loaderThatMustNotRun()), $credentials),
        ));

        self::assertSame(0, $this->loaderCalls);
    }

    private function check(string $submitted): CheckPassportEvent
    {
        return new CheckPassportEvent(
            self::createStub(AuthenticatorInterface::class),
            new Passport(new UserBadge('jane', $this->loaderThatMustNotRun()), new PasswordCredentials($submitted)),
        );
    }

    private function loaderThatMustNotRun(): Closure
    {
        return function (): UserInterface {
            ++$this->loaderCalls;

            return new CpgUser('jane', 'hashed');
        };
    }
}
