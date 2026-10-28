<?php

declare(strict_types=1);

namespace App\Mcp\Connection\Infrastructure\Security;

use App\Mcp\Connection\Application\AuthenticationInstantInterface;
use App\Shared\Domain\Security\CredentialsVersionClaim;
use DateTimeImmutable;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * L'instant d'authentification de la requête courante, lu dans le jeton de
 * session que le firewall `api` vient d'authentifier (#647, F1) : le `iat`
 * du JWT, que Lexik pose à la connexion (`POST /api/login_check`) et que la
 * signature du site protège.
 *
 * Le jeton authentifié est relu par Lexik lui-même (`parse()`, signature et
 * échéance vérifiées une seconde fois) plutôt que décodé ici : rien ne lit
 * une charge que le site n'aurait pas signée. Le coût — une vérification de
 * signature de plus — n'est payé qu'à l'accord d'un consentement. Rejeté : un
 * écouteur de `JWTAuthenticatedEvent` qui recopierait `iat` dans un attribut
 * du jeton, sur **chaque** requête authentifiée de l'API, pour une seule route
 * qui le lit.
 *
 * **Le site n'a aucun rafraîchissement de JWT** : un jeton n'est émis qu'à la
 * connexion (et, pour le palier de base, par `POST /api/account/base-access`,
 * qui n'atteint jamais le consentement), si bien que `iat` est bien l'instant
 * où la personne a saisi son mot de passe. Un rafraîchissement ajouté un jour
 * devra garder l'instant d'origine dans sa propre revendication, sans quoi
 * celui-ci serait remis à zéro sans nouvelle saisie — et la règle de
 * ConsentService ne protégerait plus rien.
 *
 * **La version des identifiants** (#650, S1) se lit dans le même jeton, avec la
 * même relecture : `cv`, posée à la création par CredentialsVersionClaimListener.
 *
 * Tout ce qui empêche de lire l'instant rend null, que l'appelant tient pour
 * trop ancien : aucun jeton, un jeton d'un autre type, un JWT devenu illisible
 * (expiré entre l'authentification et la lecture), un `iat` absent ou qui
 * n'est pas un entier.
 */
final readonly class JwtAuthenticationInstant implements AuthenticationInstantInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private JWTTokenManagerInterface $jwtManager,
    ) {
    }

    public function authenticatedAt(): ?DateTimeImmutable
    {
        $issuedAt = $this->payload()['iat'] ?? null;

        return \is_int($issuedAt) ? new DateTimeImmutable('@'.$issuedAt) : null;
    }

    /**
     * La revendication `cv` : un entier, sinon null — absente (jeton d'avant le
     * déploiement), qui n'est pas un entier ou illisible. Le consentement, qui ne
     * lit rien pour un compte dont le mot de passe n'a jamais changé, tient null
     * pour la version 0 : refusé dès que le mot de passe a changé, ce que seul un
     * jeton absent ou illisible produit ici.
     */
    public function credentialsVersion(): ?int
    {
        $claim = $this->payload()[CredentialsVersionClaim::NAME] ?? null;

        return \is_int($claim) ? $claim : null;
    }

    /**
     * La charge du jeton de session, relue par Lexik (signature et échéance
     * revérifiées) ; vide quand il n'y a pas de jeton de session ou qu'il n'est
     * plus lisible.
     *
     * @return array<array-key, mixed>
     */
    private function payload(): array
    {
        $token = $this->tokenStorage->getToken();

        if (!$token instanceof JWTPostAuthenticationToken) {
            return [];
        }

        try {
            return $this->jwtManager->parse($token->getCredentials());
        } catch (JWTDecodeFailureException) {
            return [];
        }
    }
}
