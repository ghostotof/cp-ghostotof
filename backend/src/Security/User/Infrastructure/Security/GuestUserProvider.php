<?php

declare(strict_types=1);

namespace App\Security\User\Infrastructure\Security;

use App\Security\User\Domain\ValueObject\GuestUser;
use Lexik\Bundle\JWTAuthenticationBundle\Security\User\PayloadAwareUserProviderInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * ADR 0003 D6. Chaîné AVANT `cpg_users` dans security.yaml
 * (providers.app_users.chain), utilisé par le firewall "api". Le bundle Lexik
 * privilégie loadUserByIdentifierAndPayload() sur les providers qui
 * l'implémentent (cf. JWTAuthenticator::loadUser()) : le payload déjà
 * vérifié en signature à ce stade est la seule source de vérité consultée
 * ici, jamais l'identifiant seul — impossible de forger le marqueur "guest"
 * sans la clé privée du serveur.
 *
 * Pour un jeton "normal" (compte réel), ce provider lève systématiquement,
 * et la chaîne retombe sur `cpg_users` (Doctrine) sans aucun changement de
 * comportement.
 */
final class GuestUserProvider implements PayloadAwareUserProviderInterface
{
    private const string GUEST_CLAIM = 'guest';

    /**
     * @param array<array-key, mixed> $payload
     */
    public function loadUserByIdentifierAndPayload(string $identifier, array $payload): UserInterface
    {
        if (true !== ($payload[self::GUEST_CLAIM] ?? null)) {
            throw new UserNotFoundException(sprintf('"%s" n\'est pas un jeton du palier de base.', $identifier));
        }

        return new GuestUser($identifier);
    }

    /**
     * Jamais appelé en pratique : le JWTAuthenticator privilégie toujours
     * loadUserByIdentifierAndPayload() pour un provider qui l'implémente (cf.
     * docblock de la classe). Sans le payload signé, impossible de distinguer
     * un jeton invité d'un identifiant quelconque — on refuse systématiquement
     * plutôt que de risquer un faux positif.
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new UserNotFoundException(sprintf('"%s" n\'est pas un jeton du palier de base.', $identifier));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof GuestUser) {
            throw new UnsupportedUserException(sprintf('Instances de "%s" non supportées.', get_debug_type($user)));
        }

        // Stateless (firewall "api" sans session) : rien à recharger.
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return GuestUser::class === $class;
    }
}
