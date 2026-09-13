<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issue #77 : `Request::getPathInfo()` renvoie le chemin NON décodé alors
 * que le routeur, les firewalls et l'access_control de Symfony décident
 * sur `rawurldecode()`. Tout listener kernel.request qui compare le chemin
 * doit passer par ce helper pour voir la même route que Symfony.
 */
final class CanonicalPathTest extends TestCase
{
    public function testAPlainPathIsReturnedUnchanged(): void
    {
        self::assertSame('/api/logout', CanonicalPath::of(Request::create('/api/logout', 'POST')));
    }

    public function testPercentEncodedCharactersAreDecodedLikeTheRouterDoes(): void
    {
        // `%61` = 'a' : Symfony route /%61pi/logout vers /api/logout.
        self::assertSame('/api/logout', CanonicalPath::of(Request::create('/%61pi/logout', 'POST')));
        // `%2D` = '-' : le cas prouvé sur base-access / password-setup.
        self::assertSame('/api/account/base-access', CanonicalPath::of(Request::create('/api/account/base%2Daccess', 'POST')));
    }

    public function testAPlusSignIsNotTurnedIntoASpace(): void
    {
        // rawurldecode(), pas urldecode() : '+' est un caractère littéral dans
        // un chemin (seul %20 est une espace), comme pour le routeur.
        self::assertSame('/api/a+b', CanonicalPath::of(Request::create('/api/a+b', 'GET')));
    }

    public function testTheQueryStringIsNeverPartOfThePath(): void
    {
        self::assertSame('/api/contact', CanonicalPath::of(Request::create('/api/contact?x=%61', 'POST')));
    }
}
