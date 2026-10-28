<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Le remplacement au premier échange d'un code (#726, D2), décidé ici, en
 * Application (#726, S3) : la connexion que le code désigne est gardée, les
 * autres connexions actives de son couple compte/client sont révoquées,
 * cause `superseded`, à l'instant de l'horloge.
 *
 * **Seul l'échange d'un code remplace** : un rafraîchissement, comme toute
 * autre grant, ne remplace rien — c'est l'échange qui prouve que le client a
 * reçu la nouvelle connexion. Le grant est lu dans le corps que
 * FormEncodedRequest a tiré du corps brut contrôlé, le même que league a lu,
 * et comparé à la constante d'OAuthProfile, celle que les métadonnées
 * annoncent. La connexion du code, seule Infrastructure\League sait la lire
 * (la charge chiffrée) : le port ExchangedCodeConnectionInterface.
 *
 * **L'instance gérée** : `findOneById()` rend celle que
 * `AuthCodeRepository::isAuthCodeRevoked()` a relue et verrouillée `FOR
 * UPDATE` dans la même transaction (la carte d'identité de Doctrine), son
 * compte et son client joints. Appelé après une réponse de league, donc après
 * un échange validé : le code était authentique, vivant, lié à son client et
 * prouvé par son vérificateur PKCE.
 *
 * **Écartée** (#726, CR5), et le reste : accrocher *le remplacement* à
 * `AuthCodeRepository::revokeAuthCode()`, que league appelle en dernier, avec
 * un collecteur des connexions remplacées — un état par requête à vider (un
 * service partagé qu'une requête suivante du même processus relirait), et
 * l'audit après le COMMIT resterait de toute façon au point de jeton.
 * `revokeAuthCode()` n'est accroché, depuis #650 (R4), que pour **une**
 * écriture sans état : celle de `code_exchanged_at` (ci-dessous), une
 * instruction SQL qui ne collecte rien et n'a rien à rendre.
 *
 * **Elle ne note plus le premier échange** (#730, déplacé par #650, R4) :
 * `code_exchanged_at` est écrit par la révocation du code
 * (`AuthCodeRepository::revokeAuthCode()` puis
 * `McpConnectionRepositoryInterface::recordCodeExchangeOf()`), le dernier
 * geste de league, en une instruction. Écrit ici, il dépendait du
 * déchiffrement du code et d'une relecture de la connexion, et le retour
 * anticipé ci-dessous (code illisible, connexion introuvable) le sautait avec
 * le remplacement : la connexion qui sert, jetons émis, restait « jamais
 * échangée » et expirait en une heure, sans un mot.
 *
 * **Un remplacement sauté est signalé** (#650, R5a) : après un échange réussi,
 * un code qui ne désigne aucune connexion ou une connexion que la base ne
 * connaît plus est tenu pour impossible ; si cela arrive, l'ancienne connexion
 * du couple reste vivante sans que rien le dise. Une ligne `critical`, sans
 * secret ni identifiant, et la réponse de l'échange inchangée.
 *
 * Coût, à chaque échange réussi seulement : un déchiffrement de plus que
 * league (le port), une requête de lecture (servie par la carte d'identité
 * quand league a verrouillé la connexion) et l'`UPDATE` de
 * revokeOtherActiveOf(), servi par l'index partiel des couples actifs.
 *
 * **Son audit, après le COMMIT, est ici aussi** (#726, S4) : le point de jeton
 * l'appelle une fois la transaction validée, et n'écrit plus lui-même au
 * journal de sécurité — la deuxième porte de `mcp-oauth.md` (« An assumed
 * exception… ») passe par ce service, le seul écrivain de `security_audit`
 * restant SecurityAuditLoggerInterface.
 */
final readonly class CodeExchangeSupersession implements CodeExchangeSupersessionInterface
{
    /** Le message d'une panne de l'audit d'un remplacement, constant (#726). */
    private const string SUPERSEDED_NOT_AUDITED = 'Superseded connection not audited.';

    /**
     * Les deux messages du remplacement sauté après un échange réussi (#650,
     * R5a), constants : ni le code, ni l'identifiant de la connexion, ni rien
     * de la requête.
     */
    private const string CODE_NAMES_NO_CONNECTION = 'Exchanged code names no connection; supersession skipped.';
    private const string CONNECTION_NOT_FOUND = 'Exchanged code\'s connection not found; supersession skipped.';

    public function __construct(
        private ExchangedCodeConnectionInterface $exchangedCode,
        private McpConnectionRepositoryInterface $connections,
        private ClockInterface $clock,
        private SecurityAuditLoggerInterface $auditLogger,
        private FailSafeAudit $failSafeAudit,
        private LoggerInterface $logger,
    ) {
    }

    public function supersedeOthersOf(ServerRequestInterface $request): ?Supersession
    {
        if (OAuthProfile::AUTHORIZATION_CODE_GRANT !== FormEncodedRequest::stringParameter($request, 'grant_type')) {
            return null;
        }

        // On n'arrive ici qu'après la réponse de league : le code était
        // authentique, vivant et prouvé. Que le port n'en lise aucune connexion,
        // ou que la base ne la connaisse plus, est tenu pour impossible — mais
        // l'échange est fait, et le remplacement sauté en silence laisserait
        // l'ancienne connexion du couple vivante : le cas sort en `critical`
        // (message constant, aucun contexte), sans changer la réponse.
        $connectionId = $this->exchangedCode->connectionIdOf($request);
        if (null === $connectionId) {
            $this->logger->critical(self::CODE_NAMES_NO_CONNECTION);

            return null;
        }

        $kept = $this->connections->findOneById($connectionId);
        if (null === $kept) {
            $this->logger->critical(self::CONNECTION_NOT_FOUND);

            return null;
        }

        $now = $this->clock->now();

        // L'identifiant de l'instance gérée, celle que league a verrouillée :
        // la connexion qui remplace, nommée par chaque ligne d'audit (B3).
        return new Supersession(
            $kept->getId(),
            $this->connections->revokeOtherActiveOf($kept, RevocationCause::Superseded, $now),
        );
    }

    /**
     * Une ligne `mcp-connection-revoked` par connexion remplacée, après le
     * COMMIT. Chaque ligne nomme la connexion qui remplace (`supersededBy`,
     * #726 B3) : écrite sur la requête de l'échange, rien d'autre ne la relie à
     * l'accord qui a causé la coupure. **Sa panne ne change jamais la réponse**
     * (la règle de #725, comme l'audit d'un refus au point de jeton) : les
     * jetons sont écrits, le code consommé, l'ancienne connexion révoquée ;
     * rendu en 500, le client rejouerait son code avec son vérificateur — un
     * rejeu prouvé, qui révoquerait la nouvelle connexion à son tour. La panne
     * sort en `critical` sur le canal principal, la classe seule
     * ({@see FailSafeAudit}) ; chaque connexion est tentée, une panne n'en tait
     * pas d'autre.
     */
    public function auditSuperseded(Supersession $supersession): void
    {
        foreach ($supersession->revoked as $revoked) {
            $this->failSafeAudit->attempt(
                function () use ($revoked, $supersession): void {
                    $this->auditLogger->mcpConnectionSuperseded($revoked, $supersession->kept);
                },
                self::SUPERSEDED_NOT_AUDITED,
            );
        }
    }
}
