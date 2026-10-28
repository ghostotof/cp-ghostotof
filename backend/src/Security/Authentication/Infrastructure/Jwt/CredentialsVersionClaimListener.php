<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Jwt;

use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Security\CredentialsVersionClaim;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Copie la **version des identifiants** du compte dans le JWT de session, à sa
 * création (#650, S1 de la seconde relecture, F-A et F-B).
 *
 * **La version est lue sur le `CpgUser` de l'événement, jamais relue en base** :
 * c'est l'instance que le firewall `login` a chargée puis dont il a vérifié le
 * hachage (`UserCheckerListener` la charge, `CheckCredentialsListener` compare
 * le mot de passe saisi à son `getPassword()`), et Lexik crée le jeton pour elle.
 * Une lecture de plus ouvrirait la fenêtre que la version ferme : la vérification
 * du mot de passe — de l'ordre de 270 ms — peut précéder le COMMIT d'un changement
 * de mot de passe, la création du jeton le suivre ; relue ici, la version
 * serait la nouvelle, collée à un jeton prouvé par l'ancien mot de passe.
 * `SessionBeforePasswordChangeTest::testALoginThatReadTheOldHash…` reproduit la
 * course.
 *
 * **Tous les endroits qui émettent un JWT de session** : la connexion
 * (`POST /api/login_check`, le gestionnaire de succès de Lexik, qui crée le jeton
 * pour le compte du jeton d'authentification) passe ici. L'accès de base
 * (`BaseAccessController`, `createFromPayload()` pour un `BaseAccessUser`) passe
 * aussi par l'événement, que cet écouteur laisse intact : pas de compte, donc ni
 * mot de passe ni version (ADR 0003 D6). Aucun autre code n'émet de JWT.
 *
 * **Toujours posée, même à 0** : l'absence de revendication est celle d'un jeton
 * émis avant le déploiement (elle vaut 0, `CpgUser::claimsAStaleCredentialsVersion()`) ;
 * un jeton d'après, jamais sans elle.
 */
#[AsEventListener(event: Events::JWT_CREATED)]
final readonly class CredentialsVersionClaimListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof CpgUser) {
            return;
        }

        $event->setData([
            ...$event->getData(),
            CredentialsVersionClaim::NAME => $user->getCredentialsVersion(),
        ]);
    }
}
