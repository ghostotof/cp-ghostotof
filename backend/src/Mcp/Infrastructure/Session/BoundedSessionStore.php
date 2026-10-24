<?php

declare(strict_types=1);

namespace App\Mcp\Infrastructure\Session;

use App\Mcp\Connection\Application\McpConnectionPrincipal;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
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
 *
 * **Une session est liée à la première connexion MCP qui l'utilise** (#648,
 * décision de l'auteur du 2026-10-24). Le SDK met la réponse d'un outil en
 * file dans la session, puis vide **toute** la file vers la requête qui porte
 * l'identifiant (`Protocol::consumeOutgoingMessages()`, appelé par
 * `StreamableHttpTransport::createJsonResponse()`) : un `POST` anonyme
 * concurrent, muni de l'identifiant, aurait reçu le corpus nominatif mis en
 * file par l'appel authentifié. La connexion est lue dans le `TokenStorage`
 * ({@see McpConnectionPrincipal}, posé par le firewall `mcp` avant le SDK) ;
 * une session qu'aucune connexion n'a encore utilisée reste servie comme
 * avant. Deux exigences, tenues ici parce que ce décorateur voit chaque
 * lecture et chaque écriture de la ligne :
 *
 *  - **la liaison avant toute lecture** : une session liée à une autre
 *    connexion que celle de la requête — ou à une connexion, pour une requête
 *    anonyme — n'existe pas (`exists()`, que le SDK consulte pour tout
 *    identifiant reçu avant de lire, et le GET du contrôleur), ne se lit pas,
 *    ne s'écrit pas, ne se détruit pas (`DELETE`) : le SDK répond 404 et le
 *    client se réinitialise ;
 *  - **la liaison dans la même écriture** que la session : elle tient en tête
 *    de la valeur stockée ({@see self::BINDING_PREFIX}, l'UUID de la
 *    connexion, un saut de ligne, puis la session du SDK, jamais décodée), si
 *    bien qu'aucune ligne ne contient une réponse mise en file pour une
 *    connexion sans la désigner.
 *
 * Une course reste celle du SDK, qui n'a aucun verrou : une requête qui a lu
 * la session avant sa liaison peut la réécrire une fois, avec ce qu'elle
 * avait lu, non liée **ou liée à sa propre connexion** (la dernière écriture
 * gagne, la première connexion reçoit alors un 404), ou la détruire si son
 * écriture dépasse {@see self::MAX_BYTES} — jamais avec la réponse de la
 * connexion (perdue, comme toute mise à jour concurrente d'une même session),
 * jamais en livrant le corpus d'une autre connexion : la vidange relit le
 * store et refuse une liaison étrangère. De même, une connexion qui connaît
 * l'identifiant d'une session ouverte en anonyme peut la prendre, son
 * titulaire anonyme recevant un 404 (des UUID v4 du serveur) ; et une même
 * requête refusée peut écrire deux `warning` (la lecture de la vidange, puis
 * l'écriture refusée). Limites acceptées, relecture de sécurité de #648
 * (`.claude/rules/mcp.md`). La révision sans session
 * 2026-07-28 n'est pas concernée : `StatelessProtocol` sert chaque requête
 * sur une session en mémoire, jamais sur ce store.
 */
#[AsDecorator('mcp.server.portfolio.session.store')]
final class BoundedSessionStore implements SessionStoreInterface, ResetInterface
{
    /** Taille maximale d'une session sérialisée, en octets (la session du SDK, sans l'en-tête de liaison). */
    public const int MAX_BYTES = 512 * 1024;

    /**
     * En tête de la valeur stockée d'une session liée, suivi de l'UUID RFC
     * 4122 de la connexion et d'un saut de ligne. Une session du SDK est un
     * `json_encode()` de tableau : elle commence par `{` ou `[`, jamais par
     * ce préfixe, et une ligne écrite avant la liaison se relit telle quelle.
     */
    private const string BINDING_PREFIX = 'mcp-connection:';

    /** Longueur d'un UUID RFC 4122. */
    private const int UUID_LENGTH = 36;

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

    /**
     * La liaison de chaque session lue par la requête, telle que la ligne la
     * portait à la dernière lecture ou écriture : l'UUID de la connexion, ou
     * `null` — non liée, ou absente (une session neuve).
     *
     * @var array<string, string|null>
     */
    private array $boundTo = [];

    public function __construct(
        #[AutowireDecorated]
        private readonly SessionStoreInterface $inner,
        private readonly TokenStorageInterface $tokenStorage,
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
        $stored = $this->inner->read($id);
        if (false !== $stored) {
            $data = $this->unwrap($id, $stored);
            if (false === $data) {
                $this->logger->warning('MCP session unreadable: present in the store but not read back.');

                return false;
            }

            // Liée à une autre connexion : rien n'est rendu au SDK, qui
            // répond 404 sans avoir rien lu ni vidé de la session.
            if (!$this->servesThisRequest($id)) {
                return false;
            }

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

        // Une lecture sans contrôle d'existence avant elle : la session neuve
        // d'un `initialize`, ou la vidange de la file après `save()` — qu'une
        // requête concurrente a pu lier entre-temps.
        $stored = $this->inner->read($id);
        if (false === $stored) {
            $this->boundTo[$key] = null;

            return false;
        }

        $data = $this->unwrap($id, $stored);
        if (false === $data || !$this->servesThisRequest($id)) {
            return false;
        }

        return $data;
    }

    public function write(Uuid $id, string $data): bool
    {
        // Ce que le contrôle avait lu n'est plus la session.
        unset($this->readOnReceipt[$id->toRfc4122()]);

        if ($this->wasDestroyedOverTheBound($id) || !$this->servesThisRequest($id)) {
            return false;
        }

        if (\strlen($data) > self::MAX_BYTES) {
            $this->inner->destroy($id);
            $this->destroyedOverTheBound[$id->toRfc4122()] = true;
            // Ni l'identifiant de session ni le contenu : seulement la taille.
            $this->logger->warning('MCP session refused: over the size bound.', ['bytes' => \strlen($data), 'maxBytes' => self::MAX_BYTES]);

            return false;
        }

        // La liaison part dans la même écriture que la session : celle que la
        // ligne portait, sinon la connexion de la requête, qui la lie.
        $key = $id->toRfc4122();
        $connection = $this->boundTo[$key] ?? $this->requestConnection();
        $written = $this->inner->write($id, null === $connection ? $data : self::BINDING_PREFIX.$connection."\n".$data);
        if ($written) {
            $this->boundTo[$key] = $connection;
        } else {
            // Psr16SessionStore avale toute exception et rend false : sans
            // cette ligne, l'échec serait silencieux (ADR 0005).
            $this->logger->warning('MCP session not saved by the store.', ['bytes' => \strlen($data)]);
        }

        return $written;
    }

    public function destroy(Uuid $id): bool
    {
        $key = $id->toRfc4122();
        unset($this->readOnReceipt[$key]);

        // Un `DELETE` ne lit pas la session avant : sa liaison est lue ici.
        // Liée à une autre connexion, elle n'existe pas pour la requête, qui
        // ne la détruit pas — le SDK répond 200 comme à un identifiant inconnu.
        if (!$this->servesThisRequest($id)) {
            return false;
        }

        unset($this->boundTo[$key]);

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
        $this->boundTo = [];
    }

    /**
     * La session du SDK que porte la valeur stockée, sa liaison retenue pour
     * la requête ; `false` sur un en-tête de liaison illisible (seul ce
     * décorateur l'écrit, sous le MAC du cache : une ligne qu'il n'a pas
     * écrite).
     */
    private function unwrap(Uuid $id, string $stored): string|false
    {
        $key = $id->toRfc4122();
        if (!str_starts_with($stored, self::BINDING_PREFIX)) {
            $this->boundTo[$key] = null;

            return $stored;
        }

        $offset = \strlen(self::BINDING_PREFIX);
        $connection = substr($stored, $offset, self::UUID_LENGTH);
        if ("\n" !== substr($stored, $offset + self::UUID_LENGTH, 1) || !Uuid::isValid($connection)) {
            unset($this->boundTo[$key]);

            return false;
        }

        $this->boundTo[$key] = $connection;

        return substr($stored, $offset + self::UUID_LENGTH + 1);
    }

    /**
     * La session sert-elle la requête en cours ? Non liée, oui ; liée, à la
     * seule connexion qui l'a liée. Une session dont la requête n'a rien lu
     * encore (un `DELETE`) est lue d'abord, pour connaître sa liaison.
     */
    private function servesThisRequest(Uuid $id): bool
    {
        $key = $id->toRfc4122();
        if (!\array_key_exists($key, $this->boundTo)) {
            $stored = $this->inner->read($id);
            if (false !== $stored && false === $this->unwrap($id, $stored)) {
                return false;
            }

            $this->boundTo[$key] ??= null;
        }

        $connection = $this->boundTo[$key];
        if (null === $connection || $connection === $this->requestConnection()) {
            return true;
        }

        // Ni l'identifiant de session ni celui de la connexion.
        $this->logger->warning('MCP session refused: bound to another MCP connection than the request\'s.');

        return false;
    }

    /** L'UUID de la connexion MCP que le firewall `mcp` a authentifiée pour la requête, `null` sans connexion. */
    private function requestConnection(): ?string
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof McpConnectionPrincipal ? $user->connectionId()->toRfc4122() : null;
    }

    private function wasDestroyedOverTheBound(Uuid $id): bool
    {
        return isset($this->destroyedOverTheBound[$id->toRfc4122()]);
    }
}
