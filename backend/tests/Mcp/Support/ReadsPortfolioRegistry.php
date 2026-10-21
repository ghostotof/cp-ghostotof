<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Support;

use App\Mcp\Presentation\Tool\BackedByRoute;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Prompt;
use ReflectionClass;

/**
 * Le registre réel du serveur `portfolio`, lu une fois le serveur construit :
 * il se remplit à la construction, comme à la première requête. Écrit une
 * fois pour les tests qui confrontent le catalogue à une autre source — les
 * routes (`McpExposureSymmetryTest`), la page « Brancher votre assistant »
 * (`ConnectAssistantPageMirrorsCatalogueTest`) — au lieu d'une copie par test
 * (relecture de #673). Un trait de `KernelTestCase`, comme {@see SpeaksMcp} :
 * le registre est un service privé, que seul le conteneur de test sert.
 */
trait ReadsPortfolioRegistry
{
    /**
     * Nom → classe de chaque outil du serveur.
     *
     * @return array<string, class-string>
     */
    private function registeredToolClasses(): array
    {
        $registry = $this->portfolioRegistry();

        $classes = [];
        foreach ($registry->getTools() as $name => $tool) {
            self::assertIsString($name);
            $handler = $registry->getTool($name)->handler;
            self::assertIsArray($handler, \sprintf('L\'outil « %s » n\'est pas une méthode de classe.', $name));
            $class = $handler[0];
            self::assertIsString($class);
            self::assertTrue(class_exists($class));
            $classes[$name] = $class;
        }
        self::assertNotEmpty($classes, 'Aucun outil au registre : le test ne vérifie plus rien.');

        return $classes;
    }

    /**
     * Nom → route que chaque outil déclare (`#[BackedByRoute]`), `null` pour
     * un outil sans route.
     *
     * @return array<string, ?BackedByRoute>
     */
    private function declaredToolRoutes(): array
    {
        $routes = [];
        foreach ($this->registeredToolClasses() as $name => $class) {
            $attribute = new ReflectionClass($class)->getAttributes(BackedByRoute::class)[0] ?? null;
            $routes[$name] = $attribute?->newInstance();
        }

        return $routes;
    }

    /**
     * Nom → déclaration de chaque prompt du serveur.
     *
     * @return array<string, Prompt>
     */
    private function registeredPrompts(): array
    {
        $prompts = [];
        foreach ($this->portfolioRegistry()->getPrompts() as $name => $prompt) {
            self::assertIsString($name);
            self::assertInstanceOf(Prompt::class, $prompt);
            $prompts[$name] = $prompt;
        }
        self::assertNotEmpty($prompts, 'Aucun prompt au registre : le test ne vérifie plus rien.');

        return $prompts;
    }

    private function portfolioRegistry(): RegistryInterface
    {
        $container = self::getContainer();
        $container->get('mcp.server.portfolio.builder')->build();

        return $container->get('mcp.server.portfolio.registry');
    }
}
