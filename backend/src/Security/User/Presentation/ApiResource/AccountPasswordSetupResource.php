<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Security\User\Domain\Entity\CpgUser;
use App\Security\User\Infrastructure\ApiPlatform\AccountPasswordSetupProcessor;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /api/account/password-setup `{token, password}` — endpoint public
 * (aucune entrée dans access_control, exclu du contrôle CSRF cf.
 * CsrfCookieRequestSubscriber). Consomme le jeton reçu par e-mail, définit le
 * mot de passe et active le compte. Réponse 204 sans corps (`output: false`) :
 * rien à renvoyer, surtout pas le mot de passe. `read: false` : rien à
 * résoudre en amont, le processor lit le jeton dans le corps.
 *
 * Le jeton voyage dans le CORPS, jamais dans le chemin (audit A7, décision
 * D6) : un chemin d'URL finit dans les access logs du sidecar nginx et de
 * l'ingress, un corps non. Ne pas réintroduire de `{token}` dans un
 * `uriTemplate`.
 *
 * Jeton absent ou vide -> 422, inconnu -> 404, expiré / déjà utilisé -> 410,
 * quota IP dépassé -> 429 (avant toute validation, cf.
 * PasswordSetupRateLimitRequestListener).
 */
#[ApiResource(
    shortName: 'AccountPasswordSetup',
    operations: [
        new Post(
            uriTemplate: '/account/password-setup',
            status: 204,
            read: false,
            output: false,
            processor: AccountPasswordSetupProcessor::class,
        ),
    ],
)]
final class AccountPasswordSetupResource
{
    // Borne haute : un jeton réel fait 64 caractères hexadécimaux ; rien de
    // légitime n'approche 255, et on ne hache pas un corps arbitrairement long.
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $token = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: CpgUser::MIN_PASSWORD_LENGTH, max: CpgUser::MAX_PASSWORD_LENGTH)]
    // Refuse un mot de passe présent dans une fuite connue (haveibeenpwned,
    // k-anonymity). Désactivé en environnement de test (validator.yaml,
    // when@test), même choix que BackofficeUserPasswordResource.
    // skipOnError (décision D2, audit C1) : si api.pwnedpasswords.com est
    // injoignable, le mot de passe est accepté plutôt que de renvoyer 500 sur
    // ce parcours public — la longueur mini reste appliquée, et la
    // disponibilité du parcours prime sur ce contrôle unitaire.
    #[Assert\NotCompromisedPassword(skipOnError: true)]
    public string $password = '';
}
