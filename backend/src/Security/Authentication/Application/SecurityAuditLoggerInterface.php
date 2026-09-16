<?php

declare(strict_types=1);

namespace App\Security\Authentication\Application;

use App\Security\User\Domain\Entity\CpgUser;

/**
 * Journal de sécurité (D5, constat A5 du 3e audit) : l'unique point d'entrée
 * par lequel l'application dit « ceci vient de se produire, et voici qui l'a
 * fait ». Une méthode par événement, pour que la liste de ce qui est
 * journalisé se lise d'un coup ici plutôt qu'en grep sur `logger->info`.
 *
 * Qui appelle quoi :
 *  - les événements Symfony (login réussi/raté/throttlé, logout) et le 403
 *    backoffice : Infrastructure\Log\SecurityEventsSubscriber ;
 *  - les deux gardes CSRF : juste avant de lever leur exception ;
 *  - l'émission d'un jeton du palier de base : BaseAccessController ;
 *  - les actions d'administration et l'activation d'un compte : les cas
 *    d'usage de Security\User\Application, après l'action réussie.
 *
 * Ce qui sort, et rien d'autre : `event` (kebab-case, stable — c'est la clé
 * sur laquelle on filtre), l'identifiant visé (`user`, plus `userId` quand un
 * compte existe), l'auteur (`actor`, identifiant de l'utilisateur
 * authentifié ou `anonymous`), l'IP et le chemin. Jamais un mot de passe, un
 * jeton (JWT, XSRF, invitation), un e-mail, un corps de requête ni une
 * exception sérialisée — un journal de sécurité qui contient un secret est
 * lui-même une fuite. SecurityAuditLoggerTest pince cette règle avec des
 * valeurs sentinelles.
 *
 * L'auteur n'est pas un paramètre : c'est l'implémentation qui le lit dans le
 * jeton de sécurité de la requête courante. Un cas d'usage n'a ainsi rien à
 * savoir de qui l'appelle pour laisser une trace exacte.
 */
interface SecurityAuditLoggerInterface
{
    public function loginSucceeded(string $username): void;

    /** @param string|null $username identifiant tenté, s'il a pu être lu */
    public function loginFailed(?string $username): void;

    /** Refus par login_throttling : le mot de passe n'a pas été vérifié. */
    public function loginThrottled(?string $username): void;

    public function loggedOut(?string $username): void;

    /** ADR 0003 D6 : jeton ROLE_USER émis sans compte, identifié par son marqueur `guest-…`. */
    public function baseAccessIssued(string $guestIdentifier): void;

    /** Rejet par l'un des deux gardes CSRF ; le chemin dit lequel. */
    public function csrfRejected(): void;

    /** 403 sur `/api/backoffice` : un appelant authentifié sans ROLE_SUPER. */
    public function backofficeAccessDenied(): void;

    public function userInvited(CpgUser $user): void;

    public function userReinvited(CpgUser $user): void;

    /** @param bool $superAdmin ROLE_SUPER accordé (`true`) ou retiré (`false`) */
    public function roleChanged(CpgUser $user, bool $superAdmin): void;

    public function passwordChanged(CpgUser $user): void;

    public function userDeleted(CpgUser $user): void;

    /** Fin du parcours d'invitation : mot de passe défini, compte utilisable. */
    public function accountActivated(CpgUser $user): void;
}
