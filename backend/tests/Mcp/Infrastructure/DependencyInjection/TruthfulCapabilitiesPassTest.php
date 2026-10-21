<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\DependencyInjection;

use App\Mcp\Infrastructure\DependencyInjection\TruthfulCapabilitiesPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

/**
 * Le jour où le bundle renomme le builder du serveur, la compilation échoue :
 * sans quoi l'annonce reviendrait à la détection du SDK — `listChanged` compris
 * (#675) — sans que rien ne le dise.
 */
final class TruthfulCapabilitiesPassTest extends TestCase
{
    public function testAMissingBuilderFailsTheCompilation(): void
    {
        $this->expectException(ServiceNotFoundException::class);

        (new TruthfulCapabilitiesPass())->process(new ContainerBuilder());
    }
}
