<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Infrastructure\League\AccessTokensAreNotSignedException;
use App\Mcp\Connection\Infrastructure\League\NoSigningKey;
use App\Mcp\Connection\Infrastructure\League\OpaqueAccessToken;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les jetons d'accès du serveur d'autorisation sont opaques (#647) : aucune clé
 * ne les signe. La « clé privée » que league exige est {@see NoSigningKey}, qui
 * refuse toute lecture : le jour où un chemin de league voudrait un JWT, il
 * échoue franchement au lieu de signer avec une clé vide ou de repli.
 */
final class NoSigningKeyTest extends TestCase
{
    /**
     * @param Closure(NoSigningKey): mixed $read
     */
    #[DataProvider('everyRead')]
    public function testNoSigningKeyFailsClosed(Closure $read): void
    {
        $this->expectException(AccessTokensAreNotSignedException::class);

        $read(new NoSigningKey());
    }

    /**
     * @return iterable<string, array{Closure(NoSigningKey): mixed}>
     */
    public static function everyRead(): iterable
    {
        yield 'chemin' => [static fn (NoSigningKey $key): string => $key->getKeyPath()];
        yield 'phrase de passe' => [static fn (NoSigningKey $key): ?string => $key->getPassPhrase()];
        yield 'contenu' => [static fn (NoSigningKey $key): string => $key->getKeyContents()];
    }

    /**
     * Le jeton opaque reçoit la clé (league la lui passe toujours) sans la
     * lire, et se rend par son seul identifiant : la clé n'est jamais touchée.
     */
    public function testAnOpaqueAccessTokenIsItsIdentifierAndNeverReadsTheKey(): void
    {
        $token = new OpaqueAccessToken();
        $token->setPrivateKey(new NoSigningKey());
        $token->setIdentifier(str_repeat('ab', 40));

        self::assertSame(str_repeat('ab', 40), $token->toString());
    }
}
