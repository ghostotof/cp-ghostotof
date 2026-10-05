<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\CanonicalPath;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Ancrage « `/api` exactement, ou `/api/…` », sur le chemin décodé : un
     * `str_starts_with($path, '/api')` nu attraperait aussi `/apix`.
     */
    #[DataProvider('apiMembership')]
    public function testIsUnderApiIsAnchoredOnTheDecodedPath(string $uri, bool $expected): void
    {
        self::assertSame($expected, CanonicalPath::isUnderApi(Request::create($uri)));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function apiMembership(): iterable
    {
        yield 'racine de l\'API' => ['/api', true];
        yield 'route de l\'API' => ['/api/contact', true];
        yield 'chemin encodé' => ['/%61pi/contact', true];
        yield 'voisin sans séparateur' => ['/apix', false];
        yield 'voisin avec tiret' => ['/api-docs', false];
        yield 'hors API' => ['/inexistant', false];
    }

    /**
     * Même ancrage pour n'importe quel sous-arbre (issue #322) : la règle vit
     * à un seul endroit, comme celle des `access_control` (issue #78).
     */
    #[DataProvider('subtreeMembership')]
    public function testIsUnderIsAnchoredOnTheDecodedPath(string $uri, bool $expected): void
    {
        self::assertSame($expected, CanonicalPath::isUnder(Request::create($uri), '/api/assistant'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function subtreeMembership(): iterable
    {
        yield 'racine du sous-arbre' => ['/api/assistant', true];
        yield 'route du sous-arbre' => ['/api/assistant/answers', true];
        yield 'chemin encodé' => ['/api/%61ssistant/answers', true];
        yield 'voisin sans séparateur' => ['/api/assistants', false];
        yield 'parent' => ['/api', false];
    }

    /**
     * Un préfixe mal formé échouerait sans bruit : `/api/assistant/` ne
     * correspondrait plus à rien (une garde désactivée), `` correspondrait à
     * tout. L'erreur de programmation doit se voir.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformedPrefixes(): iterable
    {
        yield 'vide' => [''];
        yield 'slash final' => ['/api/assistant/'];
        yield 'racine seule' => ['/'];
        yield 'relatif' => ['api/assistant'];
    }

    #[DataProvider('malformedPrefixes')]
    public function testAMalformedPrefixIsRefused(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalPath::isUnder(Request::create('/api/assistant/answers'), $prefix);
    }
}
