<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use App\Security\User\Domain\Entity\CpgUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

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
 *  - identifiant **connu mais en attente d'activation** : son hachage est la
 *    chaîne vide (le mot de passe est défini plus tard, via le lien
 *    d'invitation), et `NativePasswordHasher::verify()` retombe alors sur
 *    `password_verify($p, '')`, qui rend `false` sans rien dériver.
 *
 * L'écart est mesurable et dit deux choses qu'on ne veut pas publier : quels
 * identifiants existent, et lesquels sont des invitations en attente — une
 * invitation en attente étant justement un compte dont le mot de passe n'est
 * pas encore choisi. Ce listener rétablit l'égalité en payant, et seulement
 * dans ces deux cas, un hachage factice avec le hasher réellement configuré
 * pour `CpgUser`.
 *
 * **Jamais pour un compte actif avec un mauvais mot de passe** : la vraie
 * vérification a déjà eu lieu, un second hachage doublerait le temps et
 * recréerait l'écart dans l'autre sens. **Jamais non plus pour un échec qui
 * n'est pas un échec d'identifiants** : le throttling doit rester bon marché
 * (c'est sa raison d'être), et un corps JSON invalide comme un mot de passe
 * vide échouent avant même que le passeport n'existe — donc avant toute
 * requête en base, uniformément pour tous les identifiants, sans rien à
 * compenser.
 *
 * `hash()` d'une constante plutôt que `verify()` contre un hachage factice
 * précalculé, pour trois raisons : le hachage précalculé serait figé dans le
 * code alors que `password_hashers` vaut `auto` (un changement d'algorithme ou
 * de coût rouvrirait l'écart en silence, et en test le coût abaissé ne
 * s'appliquerait pas) ; `MigratingPasswordHasher::verify()` essaie les hashers
 * secondaires quand le format ne correspond pas, donc coûterait *plus* que la
 * vraie vérification ; `hash()` délègue au meilleur hasher, une fois, avec les
 * mêmes paramètres que `verify()` — même nombre d'itérations, à la génération
 * du sel près. L'objectif est l'équivalence, pas l'égalité à la microseconde.
 * Rien n'est lu du mot de passe soumis : moins de surface, et rien qui puisse
 * se retrouver par accident dans un journal.
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
        $exception = $event->getException();

        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return false;
        }

        // Identifiant inconnu. AuthenticatorManager::handleAuthenticationFailure()
        // masque l'UserNotFoundException derrière une BadCredentialsException
        // dès que `expose_security_errors` n'est pas `all`, en la conservant en
        // `previous` : les deux formes sont reconnues. On décide ici *sans*
        // rappeler le chargeur — UserBadge ne mémorise pas l'échec, un second
        // appel signifierait une seconde requête SQL par identifiant inconnu.
        if ($exception instanceof UserNotFoundException || $exception->getPrevious() instanceof UserNotFoundException) {
            return true;
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
            // ce n'est pas vrai, le throttling, est écarté plus haut.
            $user = $passport->getUser();
        } catch (UserNotFoundException) {
            return true;
        } catch (\Throwable) {
            // Panne du chargeur : le 401 est déjà construit, ce listener n'a ni
            // à le changer ni à décider pour lui.
            return false;
        }

        return $user instanceof CpgUser && $user->isPendingActivation();
    }
}
