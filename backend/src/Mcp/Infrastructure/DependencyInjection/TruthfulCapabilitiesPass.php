<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\DependencyInjection;

use Mcp\Schema\ServerCapabilities;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Annonce les capacités vraies du serveur `portfolio` (#675), dans les deux
 * ères — `initialize` et `server/discover` lisent la même configuration.
 *
 * Sans elles, le SDK 0.8.1 les déduit (`Builder::detectCapabilities()`) et
 * annonce `listChanged` pour les outils, les prompts et les ressources dès
 * qu'un répartiteur d'événements est posé — le bundle pose toujours celui de
 * Symfony, que le journal des appels écoute (#643). Aucun bus ne porte pourtant
 * de notification (`subscriptions.bus: none`), et le catalogue ne change qu'au
 * déploiement : un client conforme ouvrait un `subscriptions/listen` pour rien,
 * un worker php-fpm tenu 30 s. Ce serveur n'annonce donc aucune notification ;
 * `listen` est refusé avant le SDK
 * ({@see \App\Mcp\Infrastructure\Http\InboundMessageRefusalListener}).
 *
 * Une compiler pass plutôt qu'une option : le bundle 0.14.1 n'expose pas les
 * capacités dans sa configuration, et seule une passe peut ajouter un appel à
 * la définition d'un service qu'il enregistre. `setCapabilities()` remplace
 * toute la détection : les autres capacités recopient ce qu'elle donnait
 * (journalisation, complétion, outils, prompts ; aucune ressource). Le registre
 * ne se remplit qu'à la construction du serveur, après cet appel : on ne peut
 * pas l'y lire. {@see \App\Tests\Mcp\AnnouncedCapabilitiesTest} confronte
 * l'annonce au registre réel, et rougit le jour où une ressource apparaît
 * (#654) sans que l'annonce suive.
 */
final readonly class TruthfulCapabilitiesPass implements CompilerPassInterface
{
    /** Le builder que le bundle enregistre pour le serveur `portfolio` (`mcp.yaml`). */
    private const string BUILDER = 'mcp.server.portfolio.builder';

    public function process(ContainerBuilder $container): void
    {
        // `getDefinition()` lève si le bundle renomme son service : la
        // compilation échoue plutôt que l'annonce redevienne fausse en silence.
        $container->getDefinition(self::BUILDER)->addMethodCall('setCapabilities', [
            new Definition(ServerCapabilities::class, [
                '$tools' => true,
                '$toolsListChanged' => false,
                '$resources' => false,
                '$resourcesSubscribe' => false,
                '$resourcesListChanged' => false,
                '$prompts' => true,
                '$promptsListChanged' => false,
                '$logging' => true,
                '$completions' => true,
            ]),
        ]);
    }
}
