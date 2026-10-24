<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Registry;

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Publie les `securitySchemes` de chaque outil au premier niveau de
 * `tools/list`, en plus de leur `_meta` (#648, ADR 0006 D3, décision de
 * l'auteur du 2026-10-24).
 *
 * ChatGPT n'offre la liaison de compte que pour un outil qui déclare ses
 * `securitySchemes` ; la référence de l'Apps SDK d'OpenAI les lit au premier
 * niveau de l'outil, et tient `_meta["securitySchemes"]` pour un miroir de
 * rétrocompatibilité. ChatGPT fige la liste à l'approbation du connecteur :
 * les deux places sont donc tenues dès la première publication, plutôt que
 * d'attendre de savoir en préprod laquelle il lit (#653). `Mcp\Schema\Tool`
 * 0.8.1 ne connaît que `_meta` : `getTools()` rend chaque outil de la page en
 * {@see ToolWithSecuritySchemes}, dont la valeur est lue dans ce `_meta` — la
 * seule source, l'attribut `#[McpTool(meta: …)]` de l'outil. Un outil qui
 * n'en déclare pas fait échouer `tools/list` ({@see ToolWithoutSecuritySchemesException},
 * `-32603`) plutôt que d'être publié sans schéma ou avec un schéma inventé.
 *
 * Le registre, plutôt qu'un gestionnaire de `tools/list` à nous (relecture
 * finale de #648, le motif d'abord écrit était faux). Le bundle en
 * accepterait un : il autoconfigure tout `RequestHandlerInterface` en
 * `mcp.request_handler`, que `Builder::build()` place avant ceux du SDK, dans
 * les deux ères. Mais il recopierait `ListToolsHandler` (la page de
 * `paginationLimit`, le curseur et son refus) et masquerait en silence celui
 * du SDK, réponse entière comprise. Décoré, le registre ne change que la
 * donnée que lit le gestionnaire du SDK, qui reste le seul à répondre — dans
 * une session comme sous l'enveloppe sans session 2026-07-28 ;
 * `server/discover` ne liste pas d'outil en 0.8.1, `SecuritySchemesTest` le
 * vérifie.
 *
 * Tout le reste est délégué tel quel, **`getTool()` compris** : l'appel d'un
 * outil, le journal des appels ({@see \App\Mcp\Infrastructure\Log\McpCallLog},
 * `hasTool()`) et le contrôle d'identité du chargeur par découverte du SDK
 * (`getTool() === …`) lisent les références du registre décoré, jamais une
 * recopie.
 *
 * Écart accepté : le builder du SDK charge un registre fourni par
 * `setRegistry()` par `Registry::loadFrom()` quand c'est sa propre classe
 * (`final`), qui tait les événements de changement de la liste pendant le
 * chargement, et directement sinon. Décoré, le chargement émet donc un
 * `ToolListChangedEvent` par outil (un `PromptListChangedEvent` par prompt)
 * sur le répartiteur de Symfony, à chaque construction du serveur. Aucun
 * écouteur ne les reçoit : le seul abonné du SDK (`RegistryChangePublisher`)
 * n'est câblé qu'avec un bus de notifications, et ce serveur n'en a pas
 * (`TruthfulCapabilitiesPass`, aucun `listChanged` annoncé).
 *
 * Aucun journal ici : la décoration déplace les tags du service décoré, dont
 * son canal Monolog, vers le décorateur (`.claude/rules/mcp.md`). Le registre
 * décoré garde pourtant son journal du canal `mcp` : son argument est déjà
 * résolu en `monolog.logger.mcp` quand la décoration a lieu (vérifié par
 * `debug:container` sur `…SecuritySchemesRegistry.inner`).
 *
 * @phpstan-import-type Handler from ElementReference
 */
#[AsDecorator('mcp.server.portfolio.registry')]
final readonly class SecuritySchemesRegistry implements RegistryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private RegistryInterface $inner,
    ) {
    }

    /**
     * La page du registre décoré, chaque outil recopié avec ses
     * `securitySchemes` au premier niveau ; ses clés (les noms) et son
     * curseur sont gardés.
     *
     * @throws ToolWithoutSecuritySchemesException
     */
    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $page = $this->inner->getTools($limit, $cursor);

        $tools = [];
        foreach ($page->references as $name => $tool) {
            $tools[$name] = $tool instanceof Tool ? ToolWithSecuritySchemes::of($tool) : $tool;
        }

        return new Page($tools, $page->nextCursor);
    }

    /** @param Handler $handler */
    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        return $this->inner->registerTool($tool, $handler);
    }

    /** @param Handler $handler */
    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->inner->registerResource($resource, $handler);
    }

    /**
     * @param Handler                            $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function registerResourceTemplate(
        ResourceTemplate $template,
        callable|array|string $handler,
        array $completionProviders = [],
    ): ResourceTemplateReference {
        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    /**
     * @param Handler                            $handler
     * @param array<string, class-string|object> $completionProviders
     */
    public function registerPrompt(
        Prompt $prompt,
        callable|array|string $handler,
        array $completionProviders = [],
    ): PromptReference {
        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function unregisterTool(string $name): void
    {
        $this->inner->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    public function hasTool(string $name): bool
    {
        return $this->inner->hasTool($name);
    }

    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->inner->hasResourceTemplate($uriTemplate);
    }

    public function hasPrompt(string $name): bool
    {
        return $this->inner->hasPrompt($name);
    }

    public function hasTools(): bool
    {
        return $this->inner->hasTools();
    }

    /** La référence du registre décoré, son outil d'origine : c'est elle que l'appel exécute. */
    public function getTool(string $name): ToolReference
    {
        return $this->inner->getTool($name);
    }

    public function hasResources(): bool
    {
        return $this->inner->hasResources();
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResources($limit, $cursor);
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        return $this->inner->getResource($uri, $includeTemplates);
    }

    public function hasResourceTemplates(): bool
    {
        return $this->inner->hasResourceTemplates();
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResourceTemplates($limit, $cursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->inner->getResourceTemplate($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        return $this->inner->hasPrompts();
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getPrompts($limit, $cursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        return $this->inner->getPrompt($name);
    }
}
