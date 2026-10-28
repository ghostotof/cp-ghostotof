<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use App\Security\Authentication\Application\SecurityAuditLoggerInterface;
use App\Security\User\Domain\Entity\CpgUser;
use App\Shared\Domain\Security\CredentialsVersionClaim;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Refuse, sur **toute** l'API authentifiée par le cookie `BEARER`, un JWT de
 * session ouvert avant le dernier changement de mot de passe du compte (#650, R1b
 * de la relecture, décision de l'auteur du 2026-10-28 ; S1 de la seconde
 * relecture : jugé sur la **version des identifiants**, plus sur une date).
 *
 * Le JWT n'est pas révocable individuellement (ADR 0003, risque accepté : un
 * `jti` révoqué demanderait une lecture par requête). Il ne survit pourtant
 * plus au mot de passe qu'il a prouvé : c'est la remédiation d'un cookie volé,
 * que la coupure des connexions MCP (tâche 5) ne couvrait pas — R1 l'avait
 * fermée au seul consentement ; cet écouteur la ferme partout, et le 401 de la
 * requête suivante renvoie la personne à la connexion.
 *
 * **Une seule source de vérité** : la règle est
 * `CpgUser::claimsAStaleCredentialsVersion()`, que le consentement MCP lit aussi —
 * l'**égalité** entre la version des identifiants que le JWT porte (revendication
 * `cv`, posée à sa création depuis le compte dont le hachage venait d'être
 * vérifié, CredentialsVersionClaimListener) et celle du compte rechargé à cette
 * requête. Aucune horloge, donc aucune seconde douteuse : l'ancienne règle
 * (`iat` ≤ date du changement) laissait passer une connexion qui avait lu
 * l'ancien hachage avant le COMMIT du changement mais signait son jeton une
 * seconde après (F-A), et refusait à tort une reconnexion légitime quand les
 * horloges de deux pods divergeaient (F-B).
 *
 * **Pourquoi `CheckPassportEvent` sur le firewall `api`**, et pas
 * `JWTAuthenticatedEvent`, un `UserChecker` ou un authentificateur décoré :
 *  - le passeport porte déjà le payload décodé (revendication `cv` comprise) et le compte
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
 * **Une revendication absente vaut la version 0** : un JWT émis avant le
 * déploiement n'en porte pas. Il reste servi tant que le mot de passe du compte
 * n'a pas changé (sinon chaque session ouverte au déploiement sauterait) et est
 * refusé dès qu'il a changé. **Échoue fermé** pour le reste : une revendication
 * **présente** qui n'est pas un entier est illisible, donc refusée — même pour un
 * compte jamais changé. Notre écouteur de création ne l'écrit jamais ainsi, la
 * signature du site garde ce cas hors d'atteinte. Un second authentificateur
 * ajouté un jour sur ce firewall devra porter la version dans l'attribut
 * `payload`, ou ne pas servir de compte dont le mot de passe a changé.
 *
 * **Le même jeton, une version plus récente que le compte** (une base restaurée)
 * est refusé aussi : c'est une égalité, pas un ordre.
 *
 * **Chaque refus est audité** (F-C, `session-refused-after-credentials-change`,
 * {@see SecurityAuditLoggerInterface::sessionRefusedAfterCredentialsChange()}) :
 * le compte, les deux versions, l'IP et le chemin, jamais le jeton ni le nom
 * d'utilisateur. **Pas de dédoublonnage** : après le premier 401 le navigateur
 * efface les cookies (StaleSessionCookiesClearingListener), seul un rejeu en
 * boucle d'un jeton gardé écrit plusieurs lignes — et il écrit déjà autant de
 * `Authenticator failed.` sur le canal `security`. Un dédoublonnage par le
 * `cache.app` (en base, donc conforme à l'ADR 0005) coûterait une lecture et
 * une écriture SQL par requête refusée pour retirer une ligne parmi plusieurs
 * déjà écrites, sur le chemin même qu'un attaquant martèlerait ; la cadence est
 * bornée en amont par les zones nginx, et c'est ce rejeu, justement, que la
 * personne qui lit le journal cherche à voir.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 128, dispatcher: 'security.event_dispatcher.api')]
final readonly class SessionBeforePasswordChangeRefusalListener
{
    public function __construct(
        private SecurityAuditLoggerInterface $auditLogger,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        $user = $passport->getUser();

        if (!$user instanceof CpgUser) {
            return;
        }

        $claim = $this->claimedVersion($passport);
        // `false` : une revendication présente mais illisible, refusée d'emblée.
        if (false === $claim || $user->claimsAStaleCredentialsVersion($claim)) {
            // Avant de lever (F-C) : sans cette ligne, seul `Authenticator failed.`
            // du canal `security` en gardait la trace, sans compte. La version
            // présentée (0 quand la revendication manque, `null` quand elle est
            // illisible) et la courante, jamais le jeton.
            $this->auditLogger->sessionRefusedAfterCredentialsChange($user, false === $claim ? null : ($claim ?? 0));

            throw new SessionPredatesPasswordChangeException();
        }
    }

    /**
     * La version des identifiants que le payload décodé (signature et échéance
     * déjà vérifiées par Lexik) déclare : l'entier, `null` quand la revendication
     * est absente (un jeton d'avant le déploiement, qui vaut 0), `false` quand
     * elle est présente sans être un entier.
     */
    private function claimedVersion(Passport $passport): int|false|null
    {
        $payload = $passport->getAttribute('payload');
        if (!\is_array($payload) || !\array_key_exists(CredentialsVersionClaim::NAME, $payload)) {
            return null;
        }

        $claim = $payload[CredentialsVersionClaim::NAME];

        return \is_int($claim) ? $claim : false;
    }
}
