<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Connection\Application;

use App\Mcp\Connection\Application\McpConnectionPrincipal;
use App\Security\User\Domain\Entity\CpgUser;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Le palier qu'une connexion MCP exige est recopié dans `Mcp/Connection`
 * plutôt que lu sur CpgUser (les deux portes de `.claude/rules/mcp-oauth.md`) :
 * ce test, qui peut dépendre des deux contextes, rougit le jour où l'un des
 * deux est renommé sans l'autre (relecture de sécurité de #648, I7). Sans lui,
 * le renommage refuserait toutes les connexions — un échec fermé, mais muet
 * jusqu'en production.
 */
final class McpConnectionPrincipalTest extends TestCase
{
    public function testTheTierAConnectionRequiresIsTheTrustedRoleOfTheSite(): void
    {
        // Lues par réflexion : écrites en constantes, l'égalité est connue de
        // PHPStan à l'analyse, qui refuse une assertion toujours vraie
        // (comme OverlongPasswordRefusalListenerTest).
        self::assertSame(
            (new ReflectionClassConstant(CpgUser::class, 'ROLE_TRUSTED'))->getValue(),
            (new ReflectionClassConstant(McpConnectionPrincipal::class, 'TRUSTED_TIER'))->getValue(),
        );
    }
}
