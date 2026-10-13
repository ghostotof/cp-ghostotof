<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Security;

use App\Security\User\Domain\Entity\CpgUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Refuse au login un mot de passe de plus de `CpgUser::MAX_PASSWORD_LENGTH`
 * octets **avant que le compte ne soit chargé** (issue #588, suite du
 * constat A10 : énumération de comptes au chronomètre).
 *
 * Au-delà de cette borne, celle du hasher
 * (`PasswordHasherInterface::MAX_PASSWORD_LENGTH`, comptée par `strlen()`),
 * `verify()` rend `false` sans rien dériver. Rien ne la bornait au login
 * (`JsonLoginAuthenticator` refuse seulement une valeur vide ou qui n'est pas
 * une chaîne) : un compte qui a un mot de passe répondait donc sans
 * dérivation, alors que l'identifiant inconnu payait le hachage factice de
 * {@see FailedLoginTimingEqualizer} — environ 270 ms de bcrypt au coût de
 * production, mesurables en une requête. Aucun mot de passe posé ne dépasse
 * cette borne (`PlainPasswordLength` sur les trois voies, et `hash()` la
 * refuse) : une saisie plus longue est fausse pour tous les comptes, la
 * refuser plus tôt ne ferme aucune porte.
 *
 * Refuser plutôt que compenser : le chemin ne charge plus aucun compte et ne
 * hache rien, donc il coûte la même chose quel que soit l'identifiant, et il
 * devient le moins cher au lieu de coûter un bcrypt. Ni troncature (elle
 * accepterait une saisie fausse dont les 4096 premiers octets seraient le bon
 * mot de passe) ni garde `kernel.request` (elle ne pourrait rendre le 401 de
 * Lexik qu'en court-circuitant le comptage de `login_throttling` et le journal
 * d'audit).
 *
 * **Priorité 1500, sur `CheckPassportEvent`** :
 *  - **sous** le `checkPassport` de `LoginThrottlingListener` (2080), qui pose
 *    l'identifiant tenté sur la requête : une paire bridée reçoit toujours son
 *    429, et ce refus compte par (IP, identifiant) comme tout échec ;
 *  - **au-dessus** de `UserCheckerListener::preCheckCredentials` (256),
 *    premier à charger le compte (`Passport::getUser()`), et de
 *    `CheckCredentialsListener` (0), qui vérifie le mot de passe.
 * OverlongPasswordRefusalListenerPriorityTest lit le vrai dispatcher du
 * firewall `login` et garde cette fenêtre.
 *
 * La réponse est le 401 commun de Lexik, octet pour octet
 * ({@see SubmittedPasswordTooLongException}). Le compensateur et le journal
 * d'audit reconnaissent l'exception avant d'appeler `getUser()`.
 *
 * Seule la **longueur** de la saisie est lue, jamais sa valeur. Ce n'est pas
 * une validation de mot de passe posé (`PlainPasswordLength`, dont le minimum
 * pourrait refuser un compte ancien) : seulement la borne au-delà de laquelle
 * aucun compte ne peut répondre oui. Le firewall `api` n'est pas concerné :
 * son passeport JWT ne porte pas de PasswordCredentials.
 */
#[AsEventListener(event: CheckPassportEvent::class, priority: 1500)]
final readonly class OverlongPasswordRefusalListener
{
    public function __invoke(CheckPassportEvent $event): void
    {
        $credentials = $event->getPassport()->getBadge(PasswordCredentials::class);

        // Déjà vérifiés, ils ont perdu leur valeur : la relire lèverait.
        if (!$credentials instanceof PasswordCredentials || $credentials->isResolved()) {
            return;
        }

        if (\strlen($credentials->getPassword()) > CpgUser::MAX_PASSWORD_LENGTH) {
            throw new SubmittedPasswordTooLongException();
        }
    }
}
