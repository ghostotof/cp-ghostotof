<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Tests\Mcp\Support\SpeaksMcp;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Capability\RegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Ce que le serveur annonce savoir faire (#675) : des capacités vraies, dans
 * les deux ères. Le SDK déduit `listChanged` de la seule présence d'un
 * répartiteur d'événements — câblé pour le journal des appels (#643) — et
 * l'annonçait pour les outils, les prompts et des ressources qui n'existent
 * pas, sans aucun bus pour porter la moindre notification : un client conforme
 * ouvrait pour rien un `subscriptions/listen`, qui tenait un worker 30 s.
 */
final class AnnouncedCapabilitiesTest extends WebTestCase
{
    use SpeaksMcp;

    /**
     * Ce qu'un client lit, décodé en tableaux : chaque objet vide (`{}`) y
     * devient `[]`. Écrit à la main, jamais recalculé par le code testé. Pas de
     * `logging` : le serveur n'envoie aucun `notifications/message` (relecture
     * de #675).
     */
    private const array ANNOUNCED = [
        'completions' => [],
        'prompts' => [],
        'tools' => [],
    ];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        self::forgetMcpSessions(self::getContainer()->get(EntityManagerInterface::class)->getConnection());
        parent::tearDown();
    }

    public function testTheHandshakeAnnouncesNoListChangedNotification(): void
    {
        $client = self::createClient();

        $session = $this->openMcpSession($client);

        self::assertSame(self::ANNOUNCED, $session->initializeResult['capabilities'] ?? null);
    }

    public function testTheSessionlessRevisionAnnouncesNoListChangedNotification(): void
    {
        $client = self::createClient();

        $this->postModernMcp($client, 'server/discover');
        self::assertResponseIsSuccessful();

        self::assertSame(self::ANNOUNCED, $this->decodeMcpResponse($client)['result']['capabilities'] ?? null);
    }

    /**
     * Les capacités sont écrites à la main (le registre ne se remplit qu'à la
     * construction du serveur, après qu'elles sont posées) : ce test les lie au
     * registre réel. Le jour où un outil, un prompt ou une ressource (#654)
     * apparaît ou disparaît sans que l'annonce suive, il rougit.
     */
    public function testEveryAnnouncedKindIsOneTheRegistryServes(): void
    {
        $client = self::createClient();
        $this->postModernMcp($client, 'server/discover');
        $announced = $this->decodeMcpResponse($client)['result']['capabilities'] ?? null;
        self::assertIsArray($announced);

        $registry = $this->builtRegistry();

        self::assertSame($registry->hasTools(), \array_key_exists('tools', $announced), 'outils');
        self::assertSame($registry->hasPrompts(), \array_key_exists('prompts', $announced), 'prompts');
        self::assertSame(
            $registry->hasResources() || $registry->hasResourceTemplates(),
            \array_key_exists('resources', $announced),
            'ressources',
        );
    }

    /** Le registre du serveur `portfolio`, rempli comme à la première requête. */
    private function builtRegistry(): RegistryInterface
    {
        $container = self::getContainer();
        $container->get('mcp.server.portfolio.builder')->build();

        return $container->get('mcp.server.portfolio.registry');
    }
}
