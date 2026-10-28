<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use App\Security\User\Domain\Entity\CpgUser;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Refuse, sur **toute** l'API authentifiée par le cookie `BEARER`, un JWT de
 * session émis avant le dernier changement de mot de passe du compte (#650, R1b
 * de la relecture, décision de l'auteur du 2026-10-28).
 *
 * Le JWT n'est pas révocable individuellement (ADR 0003, risque accepté : un
 * `jti` révoqué demanderait une lecture par requête). Il ne survit pourtant
 * plus au mot de passe qu'il a prouvé : c'est la remédiation d'un cookie volé,
 * que la coupure des connexions MCP (tâche 5) ne couvrait pas — R1 l'avait
 * fermée au seul consentement ; cet écouteur la ferme partout, et le 401 de la
 * requête suivante renvoie la personne à la connexion.
 *
 * **Une seule source de vérité** : la règle (antérieur **ou égal**, `iat` à la
 * seconde contre une colonne `TIMESTAMP(0)` ; un instant inconnu tenu pour
 * antérieur ; jamais vrai sans changement de mot de passe) est
 * `CpgUser::hasChangedCredentialsSince()`, que le consentement MCP lit aussi.
 *
 * **Pourquoi `CheckPassportEvent` sur le firewall `api`**, et pas
 * `JWTAuthenticatedEvent`, un `UserChecker` ou un authentificateur décoré :
 *  - le passeport porte déjà le payload décodé (`iat` compris) et le compte
 *    rechargé en base à cette requête (UserCheckerListener, priorité 256, l'a
 *    chargé) — **aucune lecture de plus**, ni requête SQL ni seconde
 *    vérification de signature : un compte dont le mot de passe n'a jamais
 *    changé ne paie que cette comparaison ;
 *  - `CheckPassportEvent` est la phase de Symfony où l'on valide des
 *    identifiants, avant que le jeton d'authentification n'existe : un refus y
 *    est une AuthenticationException ordinaire, rendue par l'authentificateur
 *    comme tout 401 de session. `JWTAuthenticatedEvent` est émis après la
 *    création du jeton et sert à l'enrichir ;
 *  - un `UserChecker` ne reçoit que le compte, jamais le payload, donc jamais
 *    l'instant d'authentification ;
 *  - décorer `JWTAuthenticator` copierait une classe du vendor pour y ajouter
 *    une ligne, et l'écouteur ne dépend pas de ce que fait Lexik.
 *
 * **Inscrit sur le seul dispatcher du firewall `api`** (`dispatcher:`), le seul
 * qui lit le cookie : ni `login` (un mot de passe, pas de session), ni `mcp`
 * (jeton opaque en base, déjà révoqué par le changement de mot de passe), ni
 * `oauth` n'ont de passeport JWT. Inscrit globalement, il serait recopié sur
 * chacun. SessionBeforePasswordChangeRefusalListenerRegistrationTest le pince.
 *
 * **Priorité 128** : sous UserCheckerListener (256), qui charge le compte et
 * refuse un compte désactivé avant tout, au-dessus de CheckCredentialsListener
 * (0), qui n'a rien à vérifier sur un passeport auto-validé.
 *
 * **L'accès de base n'est pas concerné** : son utilisateur est un
 * `BaseAccessUser` reconstruit du seul payload (ADR 0003 D6), sans compte — donc
 * sans mot de passe à avoir changé. Le test porte sur la classe de l'utilisateur,
 * jamais sur la présence d'un claim.
 *
 * **Échoue fermé** : un passeport sans `iat` lisible (claim absent, ou qui n'est
 * pas un entier) est un instant inconnu, donc antérieur — pour un compte dont le
 * mot de passe a changé. Aucun authentificateur de l'API n'émet un tel jeton
 * aujourd'hui ; un second authentificateur ajouté un jour sur ce firewall devra
 * porter l'instant d'authentification dans l'attribut `payload`, ou ne pas
 * servir de compte dont le mot de passe a changé.
 *
 * **Limite acceptée** (celle de R1) : la même seconde est refusée dans les deux
 * sens — une personne qui se reconnecte dans la seconde même du changement de
 * son mot de passe reçoit un 401 et recommence.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 128, dispatcher: 'security.event_dispatcher.api')]
final readonly class SessionBeforePasswordChangeRefusalListener
{
    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        $user = $passport->getUser();

        if (!$user instanceof CpgUser) {
            return;
        }

        if ($user->hasChangedCredentialsSince($this->authenticatedAt($passport))) {
            throw new SessionPredatesPasswordChangeException();
        }
    }

    /**
     * Le `iat` du payload que l'authentificateur a décodé (signature et échéance
     * déjà vérifiées par Lexik), ou `null` quand on ne peut pas l'établir.
     */
    private function authenticatedAt(Passport $passport): ?DateTimeImmutable
    {
        $payload = $passport->getAttribute('payload');
        $issuedAt = \is_array($payload) ? ($payload['iat'] ?? null) : null;

        return \is_int($issuedAt) ? new DateTimeImmutable('@'.$issuedAt) : null;
    }
}
