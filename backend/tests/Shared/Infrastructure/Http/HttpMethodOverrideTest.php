<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Http;

use App\Mcp\Infrastructure\Http\InboundMessageRefusal;
use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Aucune méthode HTTP n'est réécrite par `X-HTTP-Method-Override`
 * (`framework.allowed_http_method_override: []`, audit de #675, C10). Symfony
 * l'honorait sur tout POST : nginx comptait un POST dans ses zones quand
 * Symfony et le SDK MCP voyaient un DELETE ou un OPTIONS, que les refus de
 * `InboundMessageRefusalListener` (POST seulement) ne jugeaient plus ; une
 * valeur invalide levait une `SuspiciousOperationException`, une ligne
 * `request.ERROR` à volonté. Aucun client du site n'envoie cet en-tête.
 */
final class HttpMethodOverrideTest extends WebTestCase
{
    use SpeaksMcp;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function overrides(): iterable
    {
        yield 'DELETE' => ['DELETE'];
        yield 'OPTIONS' => ['OPTIONS'];
        yield 'PUT' => ['PUT'];
        yield 'invalide' => ['DE1'];
    }

    #[DataProvider('overrides')]
    public function testThePostStaysAPost(string $override): void
    {
        self::bootKernel();

        $request = Request::create('/api/contact', 'POST', server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => $override]);

        self::assertSame('POST', $request->getMethod());
    }

    /** Le refus de `listen` (#675) tient, quelle que soit la méthode annoncée. */
    #[DataProvider('overrides')]
    public function testAnOverriddenListenIsStillRefused(string $override): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'subscriptions/listen', server: ['HTTP_X_HTTP_METHOD_OVERRIDE' => $override], id: 5);

        self::assertResponseStatusCodeSame(400);
        $error = $this->decodeMcpResponse($client)['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame(-32601, $error['code'] ?? null);
        self::assertSame(InboundMessageRefusal::SubscriptionListen->message(), $error['message'] ?? null);
    }
}
