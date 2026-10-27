<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\League;

use App\Mcp\Connection\Domain\Entity\McpAuthorizationCode;
use App\Mcp\Connection\Domain\Exception\AuthorizationCodeReplayedException;
use App\Mcp\Connection\Domain\Repository\McpAuthorizationCodeRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

/**
 * Les codes d'autorisation de league, posés sur McpAuthorizationCode (#647).
 *
 * Le client reçoit une charge chiffrée par league ; la base n'en garde que
 * l'empreinte de l'identifiant. Un code est mort s'il est inconnu ou si sa
 * connexion l'est ; révoqué (league le révoque à l'échange : usage unique)
 * sur une connexion active, il est **rejoué** (#649).
 *
 * C'est ici, dans {@see self::isAuthCodeRevoked()}, que le code est jugé
 * **sous le verrou de sa connexion** : league l'appelle depuis sa
 * validation, dans la transaction du point de jeton, avant d'émettre les
 * jetons et de révoquer le code.
 */
final readonly class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function __construct(
        private McpAuthorizationCodeRepositoryInterface $codes,
        private TokenUnderConnectionLock $underConnectionLock,
        private ConnectionOfToken $connectionOfToken,
        private ClockInterface $clock,
    ) {
    }

    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode();
    }

    /**
     * @throws UniqueTokenIdentifierConstraintViolationException quand l'empreinte est déjà prise
     * @throws UnknownMcpConnectionException                     quand le code n'est pas celui d'une connexion
     * @throws McpConnectionOfAnotherClientException             quand la connexion est celle d'un autre client
     * @throws RevokedMcpConnectionException                     quand la connexion est déjà révoquée
     */
    public function persistNewAuthCode(#[SensitiveParameter] AuthCodeEntityInterface $authCodeEntity): void
    {
        $connection = $this->connectionOfToken->of($authCodeEntity->getUserIdentifier(), $authCodeEntity->getClient());

        try {
            $this->codes->add(new McpAuthorizationCode(
                $connection,
                TokenIdentifierHash::of($authCodeEntity->getIdentifier()),
                $authCodeEntity->getExpiryDateTime(),
            ));
        } catch (UniqueConstraintViolationException) {
            // Nomme la cause, rien de plus : le flush refusé a fermé
            // l'EntityManager, le nouvel essai que league tenterait échouerait.
            // La violation n'est pas gardée : son DETAIL cite l'empreinte.
            throw UniqueTokenIdentifierConstraintViolationException::create();
        }
    }

    public function revokeAuthCode(#[SensitiveParameter] string $codeId): void
    {
        $this->codes->revokeByHash(TokenIdentifierHash::of($codeId), $this->clock->now());
    }

    /**
     * Le nom est celui de l'interface de league ; ce qu'elle juge est tout ce
     * qui rend un code irrecevable (#649), **sous le verrou de la ligne de sa
     * connexion**, dans la transaction du point de jeton — le modèle est le
     * rafraîchissement ({@see RefreshTokenRepository::isRefreshTokenRevoked()}),
     * les étapes 1 à 5 étant celles qu'ils partagent, écrites une fois dans
     * {@see TokenUnderConnectionLock::judge()} :
     *
     *  1. le code, par son empreinte — inconnu, il est mort ;
     *  2. sa connexion, relue `FOR UPDATE` (`lockForUpdate()`) : deux
     *     échanges simultanés d'un même code se suivent désormais au lieu de
     *     se croiser, chacun lisant le code non révoqué avant que l'autre
     *     l'ait échangé (la course de league, fermée ici) — disparue, le code
     *     est mort ;
     *  3. le code **relu sous le verrou** (rereadUnderConnectionLock(), la
     *     relecture sous verrou du projet, #560) : l'échange qu'une requête
     *     concurrente a validé pendant l'attente se voit maintenant —
     *     disparu (purgé pendant l'attente), le code est mort ;
     *  4. la connexion révoquée : mort, sans seconde révocation ni audit ;
     *  5. le code révoqué sur une connexion active : il a été échangé, et
     *     revient — {@see AuthorizationCodeReplayedException}, que le point
     *     de jeton rattrape après le ROLLBACK pour révoquer la connexion
     *     (OAuth 2.1 §4.1.2), et avec elle les jetons que le code a émis ;
     *  6. sinon le code est bon : league vérifie ensuite le client, la
     *     redirection et le vérificateur PKCE, émet les jetons, et révoque
     *     le code en dernier — une panne au milieu le laisse échangeable.
     *
     * L'échéance du code est jugée par league seul, sur la charge chiffrée
     * (`expire_time` contre `time()`), **avant** d'appeler ce dépôt : un code
     * rejoué après ses dix minutes est un simple `invalid_grant`, sans
     * révocation — limite acceptée, les jetons issus du code vivant déjà sous
     * rotation. League lie aussi le code à son client **après** ce dépôt,
     * l'inverse du rafraîchissement : un code rejoué sous un autre `client_id`
     * révoque la connexion de son vrai client aussi — accepté, l'identifiant
     * d'un client public étant public (`CodeReplayTest`).
     *
     * @throws AuthorizationCodeReplayedException quand un code déjà échangé revient sur une connexion active
     */
    public function isAuthCodeRevoked(#[SensitiveParameter] string $codeId): bool
    {
        $locked = $this->underConnectionLock->judge(
            $this->codes->findOneByHash(TokenIdentifierHash::of($codeId)),
            $this->codes->rereadUnderConnectionLock(...),
            AuthorizationCodeReplayedException::forConnection(...),
        );

        // Mort (étapes 1 à 4) : révoqué pour league ; rendu, il est bon (étape 6).
        return null === $locked;
    }
}
