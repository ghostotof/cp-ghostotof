<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Session;

use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

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
 *
 * **Et le reste pour la requête en cours** : le SDK, en vidant sa file
 * sortante, relit la session et la réécrit aussitôt, vide, sous le même
 * identifiant — qui restait alors valide, et la requête suivante tournait sur
 * une session vidée (ni `clientInfo` ni version négociée) au lieu du 404 qui
 * fait se réinitialiser le client (relecture de la tâche 2). Un identifiant
 * détruit ici n'existe donc plus, ni en lecture ni en écriture, jusqu'à la fin
 * de la requête (`reset()` pour un runtime persistant).
 *
 * **Une session présente mais illisible n'existe pas** (#673) : le MAC du
 * cache refusé après une rotation d'`APP_SECRET`, ou deux réplicas sur deux
 * secrets pendant un déploiement. Le SDK la servait vide puis la réécrivait
 * vide sous le même identifiant, et le client ne se réinitialisait jamais. Le
 * contrôle d'existence, que le SDK fait pour tout identifiant reçu avant de
 * le lire, lit donc la session : illisible, il rend `false`, et le SDK répond
 * 404 — comme le GET de {@see \App\Mcp\Presentation\Controller\McpEndpointController}.
 * Ce qu'il a lu est rendu à la lecture qui suit, sans seconde requête.
 */
#[AsDecorator('mcp.server.portfolio.session.store')]
final class BoundedSessionStore implements SessionStoreInterface, ResetInterface
{
    /** Taille maximale d'une session sérialisée, en octets. */
    public const int MAX_BYTES = 512 * 1024;

    /** @var array<string, true> identifiants détruits pour dépassement, par la requête en cours */
    private array $destroyedOverTheBound = [];

    /**
     * Ce que le contrôle d'existence a lu, par identifiant reçu, rendu une
     * fois à la lecture qui le suit. Une session neuve (`initialize`) n'y
     * passe jamais : le SDK la lit dans `save()` sans l'avoir vérifiée, et sa
     * lecture rend toujours `false`.
     *
     * @var array<string, string>
     */
    private array $readOnReceipt = [];

    public function __construct(
        #[AutowireDecorated]
        private readonly SessionStoreInterface $inner,
        // Par identifiant, jamais `#[WithMonologChannel]` : la décoration
        // réécrit les tags du service, et l'enregistrement partirait en
        // silence sur le canal applicatif (.claude/rules/ai.md).
        #[Autowire(service: 'monolog.logger.mcp')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        if ($this->wasDestroyedOverTheBound($id)) {
            return false;
        }

        // La lecture d'abord : une requête dans le cas courant. Son échec dit
        // « absente » ou « illisible » ; seul le store les distingue.
        $data = $this->inner->read($id);
        if (false !== $data) {
            $this->readOnReceipt[$id->toRfc4122()] = $data;

            return true;
        }

        if ($this->inner->exists($id)) {
            // Présente mais illisible : Psr16SessionStore a avalé l'échec.
            $this->logger->warning('MCP session unreadable: present in the store but not read back.');
        }

        return false;
    }

    public function read(Uuid $id): string|false
    {
        if ($this->wasDestroyedOverTheBound($id)) {
            return false;
        }

        $key = $id->toRfc4122();
        if (isset($this->readOnReceipt[$key])) {
            $data = $this->readOnReceipt[$key];
            unset($this->readOnReceipt[$key]);

            return $data;
        }

        return $this->inner->read($id);
    }

    public function write(Uuid $id, string $data): bool
    {
        // Ce que le contrôle avait lu n'est plus la session.
        unset($this->readOnReceipt[$id->toRfc4122()]);

        if ($this->wasDestroyedOverTheBound($id)) {
            return false;
        }

        if (\strlen($data) > self::MAX_BYTES) {
            $this->inner->destroy($id);
            $this->destroyedOverTheBound[$id->toRfc4122()] = true;
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
        unset($this->readOnReceipt[$id->toRfc4122()]);

        return $this->inner->destroy($id);
    }

    public function gc(): array
    {
        return $this->inner->gc();
    }

    public function reset(): void
    {
        $this->destroyedOverTheBound = [];
        $this->readOnReceipt = [];
    }

    private function wasDestroyedOverTheBound(Uuid $id): bool
    {
        return isset($this->destroyedOverTheBound[$id->toRfc4122()]);
    }
}
