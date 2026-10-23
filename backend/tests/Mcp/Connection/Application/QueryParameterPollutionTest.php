<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\QueryParameterPollution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La pollution de paramètres d'une requête brute (#647) : un nom surveillé
 * répété, ou atteint seulement par la réécriture de PHP. Chaque cas
 * « réécrit » est confronté à `parse_str()`, l'analyse même de PHP : si PHP
 * n'en tirait pas le nom surveillé, le cas ne prouverait rien.
 */
final class QueryParameterPollutionTest extends TestCase
{
    private const array WATCHED = ['client_id', 'redirect_uri', 'state'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function cleanQueries(): iterable
    {
        yield 'vide' => [''];
        yield 'chaque nom une fois' => ['client_id=a&redirect_uri=b&state=c'];
        yield 'nom %-encodé, décodé à l\'identique' => ['client%5Fid=a'];
        yield 'noms inconnus répétés' => ['x=1&x=2&client_id=a'];
        yield 'nom inconnu proche' => ['client_ids=a&client=b'];
        yield 'paires vides' => ['&&client_id=a&'];
        yield 'sans valeur' => ['client_id'];
        yield 'valeur avec =' => ['state=a=b&client_id=c'];
    }

    #[DataProvider('cleanQueries')]
    public function testACleanQueryIsNotPolluted(string $rawQuery): void
    {
        self::assertNull(QueryParameterPollution::firstPollutedName($rawQuery, self::WATCHED));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function repeatedQueries(): iterable
    {
        yield 'répété' => ['client_id=a&client_id=b', 'client_id'];
        yield 'répété, l\'un %-encodé' => ['client_id=a&client%5Fid=b', 'client_id'];
        yield 'répété plus loin' => ['state=s&client_id=a&redirect_uri=r&state=t', 'state'];
        yield 'répété sans valeur' => ['client_id=a&client_id', 'client_id'];
    }

    #[DataProvider('repeatedQueries')]
    public function testARepeatedWatchedNameIsPolluted(string $rawQuery, string $name): void
    {
        self::assertSame($name, QueryParameterPollution::firstPollutedName($rawQuery, self::WATCHED));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rewrittenQueries(): iterable
    {
        yield 'point' => ['client.id=a', 'client_id'];
        yield 'espace %-encodé' => ['client%20id=a', 'client_id'];
        yield 'plus' => ['client+id=a', 'client_id'];
        yield 'crochet non refermé' => ['client%5Bid=a', 'client_id'];
        yield 'espace de tête' => ['+client_id=a', 'client_id'];
        yield 'octet nul' => ['client_id%00x=a', 'client_id'];
        yield 'tableau' => ['client_id%5B%5D=a', 'client_id'];
        yield 'tableau indexé' => ['redirect_uri%5B0%5D=a', 'redirect_uri'];
        yield 'point, avant le vrai' => ['client.id=a&client_id=b', 'client_id'];
    }

    #[DataProvider('rewrittenQueries')]
    public function testANameOnlyWatchedAfterPhpRewritesItIsPolluted(string $rawQuery, string $name): void
    {
        parse_str($rawQuery, $parsed);
        self::assertArrayHasKey($name, $parsed, 'PHP doit tirer ce nom de la requête, sinon le cas ne prouve rien.');

        self::assertSame($name, QueryParameterPollution::firstPollutedName($rawQuery, self::WATCHED));
    }
}
