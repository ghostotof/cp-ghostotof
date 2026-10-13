<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use App\Security\User\Domain\Entity\CpgUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Throwable;

/**
 * Égalise le temps de réponse des échecs de `POST /api/login_check`
 * (3e audit de sécurité, constat A10 : énumération de comptes au chronomètre).
 *
 * Les trois échecs répondent déjà le même 401, mais pas dans le même temps :
 *
 *  - identifiant **connu et actif**, mauvais mot de passe : `CheckCredentialsListener`
 *    appelle `verify()`, soit une dérivation bcrypt/argon complète — des
 *    dizaines de millisecondes, c'est le coût voulu ;
 *  - identifiant **inconnu** : `UserBadge::getUser()` lève avant d'arriver là,
 *    la réponse part sans qu'aucune dérivation n'ait eu lieu ;
 *  - identifiant **connu mais sans mot de passe** (`CpgUser::hasPassword()`
 *    faux : une invitation dont la personne n'a pas encore défini le sien) :
 *    son hachage est la chaîne vide, et `NativePasswordHasher::verify()`
 *    retombe alors sur `password_verify($p, '')`, qui rend `false` sans rien
 *    dériver. Pas « en attente d'activation » : un ROLE_SUPER peut poser le
 *    mot de passe d'un compte invité depuis le backoffice (#238, #460), qui
 *    reste en attente d'activation alors que `verify()` a fait la vraie
 *    dérivation — le compenser doublait son temps de réponse et le désignait
 *    au chronomètre (issue #560).
 *
 * L'écart est mesurable et dit deux choses qu'on ne veut pas publier : quels
 * identifiants existent, et lesquels sont des invitations dont la personne
 * n'a pas encore choisi son mot de passe. Ce listener rétablit l'égalité en
 * payant, et seulement dans ces deux cas, un hachage factice avec le hasher
 * réellement configuré pour `CpgUser`.
 *
 * **Jamais pour un compte qui a un mot de passe** (actif, ou invité dont le
 * mot de passe a été posé depuis le backoffice) : la vraie vérification a déjà
 * eu lieu, un second hachage doublerait le temps et recréerait l'écart dans
 * l'autre sens. Cela suppose que `verify()` dérive vraiment : au-delà de
 * `CpgUser::MAX_PASSWORD_LENGTH` octets (la borne du hasher), il rend `false`
 * sans rien dériver, et un compte existant répondait plus vite qu'un
 * identifiant inconnu (issue #588). Ce cas n'arrive plus jusqu'ici :
 * {@see OverlongPasswordRefusalListener} le refuse avant tout chargement de
 * compte, par une {@see SubmittedPasswordTooLongException}. **Jamais non plus
 * pour un échec qui n'est pas un échec d'identifiants** : le throttling doit
 * rester bon marché (c'est sa raison d'être), un corps JSON invalide comme un
 * mot de passe vide échouent avant même que le passeport n'existe, et un mot
 * de passe trop long avant que le compte ne soit chargé — donc avant toute
 * requête en base, uniformément pour tous les identifiants, sans rien à
 * compenser. Le throttling et le mot de passe trop long ont un passeport :
 * ils doivent être reconnus à leur exception **avant**
 * `Passport::getUser()`, qui chargerait le compte.
 *
 * Cela suppose aussi que `verify()` dérive **une seule fois**, quel que soit
 * l'âge du hachage stocké (issue #590). Sous `algorithm: auto`, un échec sur un
 * hachage à rehacher (`needsRehash()` vrai : coût ou algorithme d'avant la
 * configuration courante) faisait essayer `SodiumPasswordHasher`, qui refait
 * un bcrypt complet : deux dérivations, quand ce listener en paie une.
 * `security.yaml` écrit donc `algorithm: bcrypt` et son coût, et les hashers
 * secondaires qui restent (Pbkdf2, MessageDigest) refusent un hachage à `$`
 * avant de rien calculer (`CpgUserPasswordHasherTest`). **Limite acceptée** : cette dérivation unique
 * se fait au coût *stocké*, ce listener paie le coût *configuré*. Après un
 * changement de coût ou d'algorithme, un compte au hachage ancien répond en
 * un temps à lui (plus court ou plus long que l'inconnu), jusqu'à sa
 * prochaine connexion réussie qui le rehache (`StalePasswordHashRehashTest`) ;
 * un compte dormant le reste. Rehacher d'avance demande le mot de passe en
 * clair : seuls un hachage encapsulé (bcrypt de l'ancien hachage, qu'aucun
 * hasher de Symfony ne sait vérifier) ou un mot de passe vidé puis une
 * nouvelle invitation (le compte devient « sans mot de passe », compensé
 * ici, au prix d'un nouveau mot de passe à choisir) l'éviteraient — ni l'un
 * ni l'autre retenu. Payer ici la différence obligerait à lire le hachage
 * stocké, sans marcher dans les deux sens. La configuration n'a pas changé depuis la création des
 * comptes (bcrypt coût 13, que produisait déjà `auto`) : aucun hachage ancien
 * n'est attendu, et le diagnostic SQL de `k8s/README.md`, « Avant de changer
 * `password_hashers` », le vérifie — avec la procédure à suivre avant tout
 * changement.
 *
 * `hash()` d'une constante plutôt que `verify()` contre un hachage factice
 * précalculé, pour trois raisons : le hachage précalculé serait figé dans le
 * code alors que `password_hashers` peut changer (un changement d'algorithme ou
 * de coût rouvrirait l'écart en silence, et en test le coût abaissé ne
 * s'appliquerait pas) ; un hachage précalculé que la configuration ne
 * produirait plus serait « à rehacher », et `MigratingPasswordHasher::verify()`
 * essaie alors les hashers secondaires après l'échec du principal ; `hash()` délègue au meilleur hasher, une fois, avec les
 * mêmes paramètres que `verify()` — même nombre d'itérations, à la génération
 * du sel près. L'objectif est l'équivalence, pas l'égalité à la microseconde.
 * Rien n'est lu du mot de passe soumis : moins de surface, et rien qui puisse
 * se retrouver par accident dans un journal. Écart accepté qui en découle
 * (audit de #588) : sur un hachage bcrypt, `verify()` pré-hache en sha512
 * une saisie de plus de 72 octets ou contenant un NUL, pas `hash()` sur la
 * constante — quelques microsecondes, noyées dans le bcrypt et le réseau,
 * hors de portée sous `login_throttling`. L'égaliser obligerait à lire la
 * saisie. Autre écart accepté (issue #590) : un identifiant inconnu mais
 * conforme à `CpgUser::USERNAME_PATTERN` paie une requête qui ne rend aucune
 * ligne, un compte existant une ligne et son hydratation Doctrine — de l'ordre
 * de 100 µs, contre un écart-type d'environ 3 ms sur bcrypt seul (mesuré sur un
 * poste, plus en pod avec le réseau) : quelques milliers d'essais par
 * identifiant pour le voir, soit des semaines pour une seule IP sous
 * `login_throttling` — des heures pour un attaquant réparti sur de
 * nombreuses adresses, la limite des compteurs par IP acceptée plus bas.
 *
 * DoS : ce hachage rend coûteux un login sur identifiant inconnu, qui l'était
 * déjà sur identifiant connu. Il reste borné par `login_throttling`
 * (`security.yaml`), qui compte 5 échecs par 15 min et par couple
 * (IP, identifiant) **et** 5 × ce nombre, soit 25, par IP seule
 * (`DefaultLoginRateLimiter` : limiteur global = `5 * max_attempts`) — c'est
 * ce compteur global qui borne l'attaquant qui fait varier l'identifiant —,
 * puis par la zone nginx `login` (10 r/m, burst 10) qui est le filet en amont.
 * Limite assumée : les deux compteurs sont par IP, un attaquant distribué les
 * contourne ; le coût par requête reste alors celui d'un login légitime raté,
 * qui est le coût que ce firewall accepte déjà.
 *
 * Priorité négative : le compteur de `login_throttling` (qui consomme sur
 * `LoginFailureEvent`) et le journal d'audit tournent avant, pour qu'aucun des
 * deux n'attende la dérivation. Le listener ne journalise rien et ne touche
 * pas à la réponse — les trois 401 doivent rester identiques octet pour octet,
 * c'est l'autre moitié de la non-énumération.
 */
#[AsEventListener(event: LoginFailureEvent::class, priority: -100)]
final readonly class FailedLoginTimingEqualizer
{
    /** Le firewall qui reçoit les identifiants (json_login), le seul où un mot de passe est vérifié. */
    private const string LOGIN_FIREWALL = 'login';

    /**
     * L'entrée du hachage factice. Le coût de bcrypt/argon ne dépend pas de
     * l'entrée, une constante suffit donc ; elle n'est ni un secret ni
     * comparée à quoi que ce soit, et n'est jamais persistée.
     */
    private const string DUMMY_INPUT = 'constant-input-for-timing-equalization';

    public function __construct(private PasswordHasherFactoryInterface $hasherFactory)
    {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        if (self::LOGIN_FIREWALL !== $event->getFirewallName()) {
            return;
        }

        if (!$this->realVerificationWasSkipped($event)) {
            return;
        }

        // Le hasher de CpgUser, pas un hasher choisi ici : mêmes algorithme et
        // coût que la vérification qui n'a pas eu lieu, y compris si
        // `security.yaml` change demain (et coût abaissé en test, comme elle).
        $this->hasherFactory->getPasswordHasher(CpgUser::class)->hash(self::DUMMY_INPUT);
    }

    /**
     * Vrai quand la réponse est partie sans qu'aucune dérivation de mot de
     * passe n'ait eu lieu *alors qu'un compte existant en aurait provoqué une*.
     */
    private function realVerificationWasSkipped(LoginFailureEvent $event): bool
    {
        $cause = LoginFailureCause::of($event->getException());

        // Identifiant inconnu : on décide *sans* rappeler le chargeur —
        // UserBadge ne mémorise pas l'échec, un second appel signifierait une
        // seconde requête SQL par identifiant inconnu.
        if (LoginFailureCause::UnknownIdentifier === $cause) {
            return true;
        }

        // Throttling, mot de passe trop long : refusés avant le chargement du
        // compte, identiquement pour tous les identifiants. Rien à compenser,
        // et surtout rien à charger.
        if ($cause->leftNoAccountLoaded()) {
            return false;
        }

        $passport = $event->getPassport();

        // Absent quand l'échec précède la construction du passeport (JSON
        // invalide, identifiant ou mot de passe vide) : aucun compte en jeu.
        if (null === $passport || !$passport->hasBadge(UserBadge::class)) {
            return false;
        }

        try {
            // Gratuit dans les cas qui arrivent ici : `CheckCredentialsListener`
            // a déjà chargé le compte, `UserBadge` le mémorise. Le seul cas où
            // ce n'est pas vrai sont écartés plus haut (LoginFailureCause).
            $user = $passport->getUser();
        } catch (UserNotFoundException) {
            return true;
        } catch (Throwable) {
            // Panne du chargeur : le 401 est déjà construit, ce listener n'a ni
            // à le changer ni à décider pour lui.
            return false;
        }

        // L'absence de mot de passe, pas l'attente d'activation (issue #560) :
        // un compte invité dont le mot de passe a été posé depuis le
        // backoffice reste en attente d'activation, mais `verify()` a déjà
        // payé la vraie dérivation sur son hachage.
        return $user instanceof CpgUser && !$user->hasPassword();
    }
}
