<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Un `Security` réel dont le jeton est fixé d'avance : aucun compte (pas de
 * jeton), ou le compte donné. Pour tester une garde « appelé sans le compte
 * attendu » sans démarrer le noyau ni passer par le pare-feu.
 */
final class FixedSecurity
{
    public static function holding(?UserInterface $user): Security
    {
        $tokenStorage = new TokenStorage();
        if (null !== $user) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        return new Security(new ServiceLocator(['security.token_storage' => static fn (): TokenStorage => $tokenStorage]));
    }
}
