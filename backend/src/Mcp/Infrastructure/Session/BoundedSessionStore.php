<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Session;

use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Uid\Uuid;

/**
 * Borne la taille d'une session MCP avant qu'elle n'atteigne la base.
 *
 * Le SDK recopie en session ce que le client déclare — `clientInfo`,
 * `capabilities` (dont les champs libres `experimental`), les « réponses »
 * qu'il envoie sous un identifiant de son choix — et réécrit la ligne entière
 * à chaque requête. Sans borne, un client anonyme remplirait `cache_items`,
 * donc le disque de PostgreSQL, et tout le site tomberait avec lui (audit de
 * la tâche 1 de la spec 0007).
 *
 * La borne doit laisser passer la plus grosse réponse légitime : le SDK met
 * aussi en file, dans la session, la réponse d'un outil avant de la renvoyer.
 * Au-delà, la session est détruite et l'écriture refusée : l'identifiant
 * rendu au client ne sert plus (404 à la requête suivante).
 */
#[AsDecorator('mcp.server.portfolio.session.store')]
final readonly class BoundedSessionStore implements SessionStoreInterface
{
    /** Taille maximale d'une session sérialisée, en octets. */
    public const int MAX_BYTES = 512 * 1024;

    public function __construct(
        #[AutowireDecorated]
        private SessionStoreInterface $inner,
        // Par identifiant, jamais `#[WithMonologChannel]` : la décoration
        // réécrit les tags du service, et l'enregistrement partirait en
        // silence sur le canal applicatif (.claude/rules/ai.md).
        #[Autowire(service: 'monolog.logger.mcp')]
        private LoggerInterface $logger,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        return $this->inner->exists($id);
    }

    public function read(Uuid $id): string|false
    {
        return $this->inner->read($id);
    }

    public function write(Uuid $id, string $data): bool
    {
        if (\strlen($data) > self::MAX_BYTES) {
            $this->inner->destroy($id);
            // Ni l'identifiant de session ni le contenu : seulement la taille.
            $this->logger->warning('MCP session refused: over the size bound.', ['bytes' => \strlen($data), 'maxBytes' => self::MAX_BYTES]);

            return false;
        }

        $written = $this->inner->write($id, $data);
        if (!$written) {
            // Psr16SessionStore avale toute exception et rend false : sans
            // cette ligne, l'échec serait silencieux (ADR 0005).
            $this->logger->warning('MCP session not saved by the store.', ['bytes' => \strlen($data)]);
        }

        return $written;
    }

    public function destroy(Uuid $id): bool
    {
        return $this->inner->destroy($id);
    }

    public function gc(): array
    {
        return $this->inner->gc();
    }
}
