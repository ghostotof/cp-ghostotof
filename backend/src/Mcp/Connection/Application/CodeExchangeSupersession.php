<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Application;

use App\Mcp\Connection\Domain\Repository\McpConnectionRepositoryInterface;
use App\Mcp\Connection\Domain\ValueObject\RevocationCause;
use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

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
 * **Écartée** (#726, CR5) : l'accroche à `AuthCodeRepository::revokeAuthCode()`,
 * que league appelle en dernier, avec un collecteur des connexions
 * remplacées — un état par requête à vider (un service partagé qu'une
 * requête suivante du même processus relirait), et l'audit après le COMMIT
 * resterait de toute façon au point de jeton.
 *
 * **Elle note aussi le premier échange** (#730) : `code_exchanged_at` est
 * écrit sur la connexion gardée, au même déclencheur (« premier échange
 * réussi »), dans la même transaction. Une connexion dont le code n'est jamais
 * échangé garde la colonne nulle et expire tôt ({@see \App\Mcp\Connection\Domain\Entity\McpConnection::deadline()}) ;
 * c'est ici, et nulle part ailleurs, qu'elle cesse de l'être. Un
 * rafraîchissement ne passe pas par là, un code ne s'échange qu'une fois.
 *
 * Coût, à chaque échange réussi seulement : un déchiffrement de plus que
 * league (le port), une requête de lecture (servie par la carte d'identité
 * quand league a verrouillé la connexion), l'`UPDATE` de la colonne et celui
 * de revokeOtherActiveOf(), servi par l'index partiel des couples actifs.
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

    public function __construct(
        private ExchangedCodeConnectionInterface $exchangedCode,
        private McpConnectionRepositoryInterface $connections,
        private ClockInterface $clock,
        private SecurityAuditLoggerInterface $auditLogger,
        private FailSafeAudit $failSafeAudit,
    ) {
    }

    public function supersedeOthersOf(ServerRequestInterface $request): ?Supersession
    {
        if (OAuthProfile::AUTHORIZATION_CODE_GRANT !== FormEncodedRequest::stringParameter($request, 'grant_type')) {
            return null;
        }

        $connectionId = $this->exchangedCode->connectionIdOf($request);
        $kept = null !== $connectionId ? $this->connections->findOneById($connectionId) : null;
        if (null === $kept) {
            return null;
        }

        // Le premier échange du code est noté sur la connexion gardée (#730) :
        // l'instance que league a verrouillée, dans la transaction de
        // l'échange, **avant** la révocation des autres — l'ordre des verrous ne
        // change pas (la connexion du code, déjà tenue, puis les autres par id).
        // `UPDATE` d'une seule colonne par l'unité de travail, sur une ligne que
        // la transaction tient `FOR UPDATE`.
        $now = $this->clock->now();
        $kept->recordCodeExchangeAt($now);
        $this->connections->save($kept);

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
