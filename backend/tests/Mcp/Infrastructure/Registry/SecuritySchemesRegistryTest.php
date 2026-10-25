<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Registry;

use App\Mcp\Infrastructure\Registry\SecuritySchemesRegistry;
use App\Mcp\Infrastructure\Registry\ToolWithoutSecuritySchemesException;
use App\Mcp\Infrastructure\Registry\ToolWithSecuritySchemes;
use Mcp\Capability\Registry;
use Mcp\Schema\Enum\IconTheme;
use Mcp\Schema\Icon;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * Le décorateur du registre qui publie `securitySchemes` au premier niveau de
 * chaque outil de `tools/list` (#648, ADR 0006 D3) : la valeur lue dans le
 * `_meta["securitySchemes"]` de l'outil, seule source, jamais inventée ; tout
 * le reste du registre, l'appel d'un outil compris, est celui du registre
 * décoré.
 */
final class SecuritySchemesRegistryTest extends TestCase
{
    private const array NO_AUTH = [['type' => 'noauth']];

    private Registry $inner;

    private SecuritySchemesRegistry $registry;

    protected function setUp(): void
    {
        $this->inner = new Registry();
        $this->registry = new SecuritySchemesRegistry($this->inner);
    }

    public function testEachListedToolCarriesTheSchemesOfItsMetaAtTheTopLevel(): void
    {
        $oauth = [['type' => 'oauth2', 'scopes' => ['nominative:read']]];
        $this->inner->registerTool(self::tool('public_tool', ['securitySchemes' => self::NO_AUTH]), self::handler());
        $this->inner->registerTool(self::tool('protected_tool', ['securitySchemes' => $oauth]), self::handler());

        $listed = $this->registry->getTools();

        self::assertSame(['public_tool', 'protected_tool'], array_keys($listed->references));
        self::assertSame(self::NO_AUTH, self::published($listed, 'public_tool')['securitySchemes']);
        $protected = self::published($listed, 'protected_tool');
        self::assertSame($oauth, $protected['securitySchemes']);
        // Le miroir reste en place : le `_meta` est recopié tel quel.
        self::assertSame(['securitySchemes' => $oauth], $protected['_meta'] ?? null);
    }

    /**
     * Un outil sans schéma n'est jamais publié, ni complété d'un schéma
     * inventé : `noauth` par défaut priverait de liaison un outil protégé
     * oublié, `oauth2` demanderait un compte pour un outil public. Une
     * `LogicException`, que le SDK rend en `-32603` (un défaut du serveur),
     * jamais une {@see \InvalidArgumentException}, qu'il rend en `-32602`.
     *
     * @param array<string, mixed>|null $meta
     */
    #[DataProvider('metaWithoutSchemes')]
    public function testAToolWithoutSchemesIsAServerFault(?array $meta): void
    {
        $this->inner->registerTool(self::tool('public_tool', ['securitySchemes' => self::NO_AUTH]), self::handler());
        $this->inner->registerTool(self::tool('forgotten_tool', $meta), self::handler());

        $this->expectException(ToolWithoutSecuritySchemesException::class);
        $this->expectExceptionMessage('forgotten_tool');

        $this->registry->getTools();
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null}>
     */
    public static function metaWithoutSchemes(): iterable
    {
        yield 'sans _meta' => [null];
        yield '_meta sans la clé' => [['openai/outputTemplate' => 'ui://widget']];
        yield 'liste vide' => [['securitySchemes' => []]];
        yield 'pas une liste' => [['securitySchemes' => ['type' => 'noauth']]];
        yield 'schéma qui n\'est pas un objet' => [['securitySchemes' => ['noauth']]];
        yield 'schéma sans type' => [['securitySchemes' => [['scopes' => ['nominative:read']]]]];
    }

    /**
     * Chaque champ de `Tool` survit à la recopie : la sortie publiée est celle
     * du SDK, plus `securitySchemes`, octet pour octet (normalisation `{}` des
     * schémas comprise).
     */
    public function testEveryFieldOfTheToolSurvivesTheCopy(): void
    {
        $tool = new Tool(
            name: 'full_tool',
            title: 'Full tool',
            inputSchema: [
                'type' => 'object',
                'properties' => ['locale' => ['type' => 'string', 'description' => 'Language.'], 'free' => []],
                'required' => null,
            ],
            description: 'A tool with every field set.',
            annotations: new ToolAnnotations(title: 'Annotated', readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false),
            icons: [new Icon('https://site.example/icon.png', 'image/png', ['48x48'], IconTheme::Light)],
            meta: ['securitySchemes' => self::NO_AUTH, 'openai/outputTemplate' => 'ui://widget'],
            outputSchema: ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => []]], 'additionalProperties' => false],
        );
        $this->inner->registerTool($tool, self::handler());

        $copy = $this->registry->getTools()->references['full_tool'];
        self::assertInstanceOf(ToolWithSecuritySchemes::class, $copy);

        $published = $copy->jsonSerialize();
        unset($published['securitySchemes']);
        self::assertSame(
            json_encode($tool, \JSON_THROW_ON_ERROR),
            json_encode($published, \JSON_THROW_ON_ERROR),
        );

        foreach (new ReflectionClass(Tool::class)->getProperties() as $property) {
            self::assertEquals($property->getValue($tool), $property->getValue($copy), \sprintf('Le champ « %s » n\'est pas recopié.', $property->getName()));
        }
    }

    /**
     * La recopie passe chaque paramètre du constructeur de `Tool` : une montée
     * du SDK qui en ajoute un (un champ que la recopie perdrait en silence, et
     * que la comparaison ci-dessus ne peut pas remplir) fait rougir ce test
     * jusqu'à ce que {@see ToolWithSecuritySchemes} le recopie.
     */
    public function testTheCopyKnowsEveryParameterOfTheSdkTool(): void
    {
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            new ReflectionMethod(Tool::class, '__construct')->getParameters(),
        );

        self::assertSame(
            ['name', 'title', 'inputSchema', 'description', 'annotations', 'icons', 'meta', 'outputSchema'],
            $parameters,
        );
    }

    /**
     * La page garde le curseur et les clés que le registre décoré lui donne
     * (les noms sans limite, des rangs une fois paginée).
     */
    public function testThePageKeepsItsCursorAndItsKeys(): void
    {
        foreach (['tool_a', 'tool_b', 'tool_c'] as $name) {
            $this->inner->registerTool(self::tool($name, ['securitySchemes' => self::NO_AUTH]), self::handler());
        }

        $inner = $this->inner->getTools(2);
        $first = $this->registry->getTools(2);
        $second = $this->registry->getTools(2, $first->nextCursor);

        self::assertNotNull($inner->nextCursor);
        self::assertSame($inner->nextCursor, $first->nextCursor);
        self::assertSame(array_keys($inner->references), array_keys($first->references));
        self::assertSame(['tool_a', 'tool_b'], self::toolNames($first));
        self::assertSame(['tool_c'], self::toolNames($second));
        self::assertNull($second->nextCursor);
    }

    /**
     * L'appel d'un outil passe par `getTool()` : la référence du registre
     * décoré, son outil d'origine et son handler, sans recopie — et un outil
     * sans schéma reste appelable, seul `tools/list` le refuse.
     */
    public function testGetToolIsTheInnerReference(): void
    {
        $reference = $this->inner->registerTool(self::tool('forgotten_tool', null), self::handler());

        self::assertSame($reference, $this->registry->getTool('forgotten_tool'));
        self::assertNotInstanceOf(ToolWithSecuritySchemes::class, $this->registry->getTool('forgotten_tool')->tool);
    }

    /**
     * Le reste du registre est délégué : ce qui s'enregistre par le
     * décorateur est au registre décoré (le builder du SDK charge les
     * éléments par le décorateur, `Builder::build()`), et inversement.
     */
    public function testEverythingElseIsDelegated(): void
    {
        $tool = $this->registry->registerTool(self::tool('a_tool', ['securitySchemes' => self::NO_AUTH]), self::handler());
        $prompt = $this->registry->registerPrompt(new Prompt('a_prompt'), self::handler());
        $resource = $this->registry->registerResource(new ResourceDefinition('file:///a', 'a_resource'), self::handler());
        $template = $this->registry->registerResourceTemplate(new ResourceTemplate('file:///{name}', 'a_template'), self::handler());

        self::assertSame($tool, $this->inner->getTool('a_tool'));
        self::assertTrue($this->registry->hasTool('a_tool'));
        self::assertTrue($this->registry->hasTools());
        self::assertSame($prompt, $this->registry->getPrompt('a_prompt'));
        self::assertTrue($this->registry->hasPrompt('a_prompt'));
        self::assertTrue($this->registry->hasPrompts());
        self::assertSame(array_keys($this->inner->getPrompts()->references), array_keys($this->registry->getPrompts()->references));
        self::assertSame($resource, $this->registry->getResource('file:///a', false));
        self::assertTrue($this->registry->hasResource('file:///a'));
        self::assertTrue($this->registry->hasResources());
        self::assertSame(array_keys($this->inner->getResources()->references), array_keys($this->registry->getResources()->references));
        self::assertSame($template, $this->registry->getResourceTemplate('file:///{name}'));
        self::assertSame($template, $this->registry->getResource('file:///b'));
        self::assertTrue($this->registry->hasResourceTemplate('file:///{name}'));
        self::assertTrue($this->registry->hasResourceTemplates());
        self::assertSame(array_keys($this->inner->getResourceTemplates()->references), array_keys($this->registry->getResourceTemplates()->references));

        $this->registry->unregisterTool('a_tool');
        $this->registry->unregisterPrompt('a_prompt');
        $this->registry->unregisterResource('file:///a');
        $this->registry->unregisterResourceTemplate('file:///{name}');

        self::assertFalse($this->inner->hasTools());
        self::assertFalse($this->inner->hasPrompts());
        self::assertFalse($this->inner->hasResources());
        self::assertFalse($this->inner->hasResourceTemplates());
    }

    /**
     * L'outil de ce nom tel que `tools/list` le publie.
     *
     * @return array<string, mixed>
     */
    private static function published(Page $page, string $name): array
    {
        $tool = $page->references[$name] ?? null;
        self::assertInstanceOf(ToolWithSecuritySchemes::class, $tool);

        return $tool->jsonSerialize();
    }

    /**
     * @return list<string>
     */
    private static function toolNames(Page $page): array
    {
        $names = [];
        foreach ($page->references as $tool) {
            self::assertInstanceOf(Tool::class, $tool);
            $names[] = $tool->name;
        }

        return $names;
    }

    /**
     * @param array<string, mixed>|null $meta
     */
    private static function tool(string $name, ?array $meta): Tool
    {
        return new Tool(
            name: $name,
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
            description: null,
            annotations: null,
            meta: $meta,
        );
    }

    /** @return array{class-string, string} */
    private static function handler(): array
    {
        return [self::class, 'handler'];
    }
}
