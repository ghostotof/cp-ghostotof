<?php

declare(strict_types=1);

namespace App\Tests\Mcp\Infrastructure\Log;

use App\Mcp\Presentation\Prompt\PromptRole;
use App\Tests\Mcp\Support\SpeaksMcp;
use App\Tests\Support\ReadsAllChannelsLog;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ce que le SDK MCP écrit sur son canal `mcp` (spec 0007, tâche 4, #643,
 * reports des relectures de #640 et #642).
 *
 * Le SDK 0.8.1 journalise le message JSON-RPC brut (« Received message to
 * process. », « Handling request. », arguments compris — le `role` du prompt
 * est du texte libre où une personne peut écrire son nom), les identifiants de
 * session, et cite sans borne en `warning`/`error` des chaînes choisies par
 * l'appelant (nom d'outil ou de méthode inconnu). Les fautes du client y sont
 * des `error` : un anonyme écrivait une ligne `error` par requête en
 * production.
 *
 * La sonde `all_channels_test` (niveau `debug`) lit ce que le canal émet à
 * **tout** niveau : ce que la préprod (`LOG_LEVEL=debug`) aurait reçu sans le
 * plafond du handler `mcp`.
 */
final class SdkChannelRecordsTest extends WebTestCase
{
    use ReadsAllChannelsLog;
    use SpeaksMcp;

    /** Une chaîne que l'appelant choisit, plus longue que ce qu'une ligne doit garder. */
    private const int HUGE = 100_000;

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
     * L'argument d'un prompt, à aucun niveau : ni le message brut, ni la requête
     * décodée, ni les arguments que le gestionnaire reçoit.
     */
    public function testAPromptArgumentReachesNoRecordOfTheChannel(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, 'prompts/get', [
            'name' => 'present_for_role',
            'arguments' => ['role' => 'Recrutement chez Sentinelle-643 SARL', 'locale' => 'fr'],
        ]);
        self::assertArrayHasKey('result', $response);

        self::assertNotSame([], $this->sdkRecords(), 'Le canal mcp doit avoir émis : sans enregistrement, le test ne prouve rien.');
        foreach ($this->formattedSdkRecords() as $line) {
            self::assertStringNotContainsStringIgnoringCase('sentinelle', $line);
        }
    }

    /**
     * Un identifiant de session vaut accès à la session : il ne sort par aucun
     * enregistrement du canal.
     */
    public function testTheSessionIdReachesNoRecordOfTheChannel(): void
    {
        $client = self::createClient();

        // `initialize` seul : la sonde ne garde que la dernière requête, et
        // c'est celle-ci qui crée la session (« Created new session »).
        $this->postMcpInitialize($client, ['name' => 'phpunit', 'version' => '1.0.0']);
        $sessionId = $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        self::assertNotSame([], $this->sdkRecords());
        foreach ($this->formattedSdkRecords() as $line) {
            self::assertStringNotContainsString($sessionId, $line);
        }

        $this->mcpCall($client, $sessionId, 'tools/list');
        foreach ($this->formattedSdkRecords() as $line) {
            self::assertStringNotContainsString($sessionId, $line);
        }
    }

    /**
     * Les fautes que le client provoque à volonté : aucune n'atteint `warning`
     * (le plafond du handler de production), et aucune chaîne qu'il a choisie
     * n'y est recopiée en entier.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('clientFaults')]
    public function testAClientFaultStaysBelowWarningAndBounded(string $method, array $params): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $response = $this->mcpCall($client, $sessionId, $method, $params);
        self::assertTrue(isset($response['error']) || true === ($response['result']['isError'] ?? null), 'Le cas doit être une faute du client.');

        $this->assertNoSdkRecordAtOrAboveWarning();
        foreach ($this->formattedSdkRecords() as $line) {
            self::assertStringNotContainsString(str_repeat('z', 1_000), $line);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function clientFaults(): iterable
    {
        yield 'outil inconnu au nom démesuré' => ['tools/call', ['name' => str_repeat('z', self::HUGE), 'arguments' => new stdClass()]];
        yield 'méthode inconnue au nom démesuré' => [str_repeat('z', self::HUGE), []];
        yield 'prompt inconnu au nom démesuré' => ['prompts/get', ['name' => str_repeat('z', self::HUGE)]];
        yield 'rôle absent' => ['prompts/get', ['name' => 'present_for_role', 'arguments' => new stdClass()]];
        yield 'langue inconnue du prompt' => ['prompts/get', ['name' => 'present_for_role', 'arguments' => ['role' => 'PHP developer', 'locale' => str_repeat('z', self::HUGE)]]];
        yield 'langue inconnue d\'un outil' => ['tools/call', ['name' => 'get_about', 'arguments' => ['locale' => str_repeat('z', self::HUGE)]]];
        yield 'complétion d\'un prompt inconnu' => ['completion/complete', ['ref' => ['type' => 'ref/prompt', 'name' => str_repeat('z', self::HUGE)], 'argument' => ['name' => 'role', 'value' => 'x']]];
    }

    /**
     * Un argument refusé n'est pas plus recopié qu'un argument accepté : ni le
     * rôle trop long, ni une langue inconnue, ni un rôle d'un autre type.
     *
     * @param array<string, mixed> $params
     */
    #[DataProvider('refusedArguments')]
    public function testARefusedArgumentReachesNoRecordOfTheChannel(string $method, array $params): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->mcpCall($client, $sessionId, $method, $params);

        self::assertNotSame([], $this->sdkRecords());
        foreach ($this->formattedSdkRecords() as $line) {
            self::assertStringNotContainsStringIgnoringCase('sentinelle', $line);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function refusedArguments(): iterable
    {
        $tooLong = str_repeat('Sentinelle-643 ', intdiv(PromptRole::MAX_CHARACTERS, 15) + 1);
        yield 'rôle refusé pour sa longueur' => ['prompts/get', ['name' => 'present_for_role', 'arguments' => ['role' => $tooLong]]];
        yield 'langue inconnue du prompt' => ['prompts/get', ['name' => 'present_for_role', 'arguments' => ['role' => 'PHP developer', 'locale' => 'Sentinelle-643']]];
        yield 'langue inconnue d\'un outil' => ['tools/call', ['name' => 'get_about', 'arguments' => ['locale' => 'Sentinelle-643']]];
        // Le SDK 0.8.1 le convertit en « Array » (avertissement PHP sur le canal
        // `php`, hors de ce canal) : le prompt répond, rien de la valeur ne sort.
        yield 'rôle d\'un autre type' => ['prompts/get', ['name' => 'present_for_role', 'arguments' => ['role' => ['Sentinelle-643']]]];
    }

    /** Un corps qui n'est pas du JSON : la faute la moins chère à répéter. */
    public function testAnUnreadableBodyStaysBelowWarning(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postRawMcp($client, $sessionId, '{"jsonrpc": "2.0", '.str_repeat('z', self::HUGE));

        $this->assertNoSdkRecordAtOrAboveWarning();
        foreach ($this->formattedSdkRecords() as $line) {
            self::assertStringNotContainsString(str_repeat('z', 1_000), $line);
        }
    }

    /**
     * Une réponse du client qui ne se rattache à rien, sans identifiant. Depuis
     * #644 elle est refusée avant le SDK (InboundMessageRefusalListener) : le
     * SDK ne l'écrit donc plus du tout, ce que le test garde.
     */
    public function testAnIdLessErrorFromTheClientStaysBelowWarning(): void
    {
        $client = self::createClient();
        $sessionId = $this->initializeMcpSession($client);

        $this->postMcp($client, $sessionId, [
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32000, 'message' => str_repeat('z', self::HUGE)],
        ]);
        self::assertResponseStatusCodeSame(400);

        $this->assertNoSdkRecordAtOrAboveWarning();
        foreach ($this->formattedSdkRecords() as $line) {
            self::assertStringNotContainsString(str_repeat('z', 1_000), $line);
        }
    }

    private function assertNoSdkRecordAtOrAboveWarning(): void
    {
        $loud = array_filter($this->sdkRecords(), static fn (LogRecord $record): bool => $record->level->isHigherThan(Level::Notice));

        self::assertSame([], array_map(static fn (LogRecord $record): string => $record->level->getName().' '.mb_substr($record->message, 0, 120), array_values($loud)));
    }

    /**
     * @return list<LogRecord>
     */
    private function sdkRecords(): array
    {
        return self::allChannelsLogRecordsOf('mcp');
    }

    /**
     * Chaque enregistrement tel que le formateur JSON de production l'écrirait.
     *
     * @return list<string>
     */
    private function formattedSdkRecords(): array
    {
        $formatter = new JsonFormatter();

        return array_map(static fn (LogRecord $record): string => $formatter->format($record), $this->sdkRecords());
    }
}
