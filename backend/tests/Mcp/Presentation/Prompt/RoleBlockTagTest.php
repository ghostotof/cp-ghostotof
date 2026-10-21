<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Presentation\Prompt;

use App\Mcp\Presentation\Prompt\RoleBlockTag;
use PHPUnit\Framework\TestCase;

/**
 * La balise du bloc `role` porte un nonce tiré à chaque prompt (#662) : le
 * tiers qui piège une annonce l'écrit avant que le nonce existe, il ne peut
 * donc pas refermer le bloc, quels que soient les sosies qu'il emploie.
 */
final class RoleBlockTagTest extends TestCase
{
    public function testItIsTheWordRoleFollowedByARandomNonce(): void
    {
        self::assertMatchesRegularExpression('/\Arole-[0-9a-f]{16}\z/', RoleBlockTag::random()->name);
    }

    public function testEveryTagHasItsOwnNonce(): void
    {
        self::assertNotSame(RoleBlockTag::random()->name, RoleBlockTag::random()->name);
    }

    public function testItOpensAndClosesUnderItsName(): void
    {
        $tag = RoleBlockTag::random();

        self::assertSame('<'.$tag->name.'>', $tag->opening());
        self::assertSame('</'.$tag->name.'>', $tag->closing());
    }
}
