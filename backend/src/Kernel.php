<?php

declare(strict_types=1);

namespace App;

use App\Mcp\Infrastructure\DependencyInjection\TruthfulCapabilitiesPass;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    protected function build(ContainerBuilder $container): void
    {
        // Capacités vraies du serveur MCP (#675) : voir la passe.
        $container->addCompilerPass(new TruthfulCapabilitiesPass());
    }
}
