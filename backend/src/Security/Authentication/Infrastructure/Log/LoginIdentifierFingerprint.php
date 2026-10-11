<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Shared\Infrastructure\Security\EmptyApplicationSecretException;
use App\Shared\Infrastructure\Security\SecretKeyDerivation;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Empreinte d'un identifiant tenté au login, pour le journal de sécurité
 * (issue #418).
 *
 * L'identifiant saisi n'est pas une donnée sûre : une personne qui tape son
 * mot de passe dans le champ identifiant — ou colle les deux ensemble — le
 * ferait partir en clair dans les journaux de production. Le filtrage par
 * {@see \App\Security\User\Domain\Entity\CpgUser::USERNAME_PATTERN} ne
 * suffit pas : un mot de passe de trousseau (`abcdef-ghijk2-mnoPqr`) ou
 * purement alphanumérique, le cas le plus courant, respecte ce motif. L'empreinte, elle, ne laisse jamais lire la
 * valeur et garde ce dont l'investigation a besoin : reconnaître qu'une même
 * valeur revient, d'une tentative à l'autre et d'un `login-failed` au
 * `login-throttled` qu'ils déclenchent.
 *
 * - **À clé** (HMAC-SHA256 sous une clé dérivée de `kernel.secret`, c.-à-d. `APP_SECRET`), pas
 *   un simple condensat : sans la clé, passer un dictionnaire de mots de
 *   passe sur les empreintes d'un journal ne donne rien. Une clé vide est
 *   refusée ({@see \App\Shared\Infrastructure\Security\EmptyApplicationSecretException}). La clé protège
 *   déjà la signature des jetons CSRF et les clés des compteurs de
 *   `login_throttling` (HMAC de `identifiant-IP`, 48 bits, en base dans
 *   `cache_items`) : sa fuite est un incident en soi. La clé est dérivée pour
 *   ce seul usage (issue #455, {@see \App\Shared\Infrastructure\Security\SecretKeyDerivation}) :
 *   aucune empreinte ne vaut HMAC d'un autre usage du secret, ni l'inverse.
 *   Elle a remplacé un préfixe de contexte dans le message, qui ne séparait
 *   pas les usages de façon absolue. Changer de clé change toutes les
 *   empreintes, comme une rotation d'`APP_SECRET`.
 * - **Insensible à la casse comme le compteur** :
 *   {@see \Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter}
 *   compte `mb_strtolower()` de l'identifiant (`strtolower()` hors UTF-8) ;
 *   `Jane` et `jane` partagent un compteur, ils partagent donc une empreinte,
 *   sinon le refus déclenché par l'un ne se relierait plus aux échecs de
 *   l'autre.
 * - **Courte exprès** : 6 caractères hexadécimaux, 24 bits (relecture
 *   sécurité de #418). Avec la clé, une empreinte longue confirmerait hors
 *   ligne, au rythme d'un SHA-256, un mot de passe tapé comme identifiant —
 *   et un `login-succeeded` depuis la même IP juste après dit à qui il
 *   appartient : le journal protégerait ce mot de passe moins bien que la
 *   base (bcrypt/argon). À 24 bits, un dictionnaire de 2^30 mots laisse une
 *   soixantaine de candidats ; un mot de passe d'une liste courte reste
 *   devinable, la troncature n'y peut rien. La corrélation, elle, se lit à IP
 *   et fenêtre de 15 min fixées, où une collision fortuite (2^-24 par paire)
 *   est négligeable ; entre IP différentes, une même empreinte est un indice,
 *   pas une preuve.
 *
 * Le prix : une rotation d'`APP_SECRET` change toutes les empreintes — les
 * lignes d'avant et d'après ne se relient plus entre elles.
 */
final readonly class LoginIdentifierFingerprint
{
    /**
     * Contexte de la clé dérivée d'`APP_SECRET` (issue #455) : la séparation
     * de domaine se fait par la clé, plus par un préfixe du message.
     */
    private const string KEY_CONTEXT = 'cp-ghostotof/login-identifier-fingerprint/v1';

    /** 24 bits en hexadécimal : voir « Courte exprès » ci-dessus. */
    private const int LENGTH = 6;

    public function __construct(
        #[SensitiveParameter]
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    /**
     * La clé vide est refusée ici, au calcul, et non à la construction :
     * SecurityAuditLogger, qui en dépend, est injecté dans des écouteurs de
     * chaque requête (gardes CSRF) et dans des commandes ; un refus au
     * constructeur rendrait toute l'API indisponible, quand seul le login a
     * besoin de l'empreinte — comme le limiteur de `login_throttling`, qui
     * refuse lui aussi une clé vide.
     *
     * @param string $identifier l'identifiant tel que saisi — possiblement un
     *                           mot de passe, d'où l'attribut
     *
     * @throws EmptyApplicationSecretException si `kernel.secret` est vide (SecretKeyDerivation)
     */
    public function of(#[SensitiveParameter] string $identifier): string
    {
        // Même repli de casse que
        // Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter::getLimiters().
        $folded = 1 === preg_match('//u', $identifier) ? mb_strtolower($identifier, 'UTF-8') : strtolower($identifier);

        return substr(hash_hmac('sha256', $folded, SecretKeyDerivation::derive($this->secret, self::KEY_CONTEXT)), 0, self::LENGTH);
    }
}
