<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fabrique et vérifie la valeur du cookie CSRF `XSRF-TOKEN` (double-submit
 * cookie, cf. CsrfCookieRequestSubscriber / Jwt\LoginSuccessSubscriber).
 *
 * Point d'audit B1 : jusqu'ici la valeur était un simple `random_bytes(32)`
 * ni signé ni lié à quoi que ce soit. Un attaquant capable de *poser* un
 * cookie sur le domaine (XSS sur un futur sous-domaine, MITM sur un
 * sous-domaine servi en clair) pouvait donc fixer `XSRF-TOKEN` à une valeur
 * qu'il connaît, puis la recopier dans l'en-tête `X-XSRF-TOKEN` : le
 * double-submit seul (cookie == header) était satisfait.
 *
 * La valeur devient `<random>.<hmac>` où `hmac = HMAC-SHA256(random, APP_SECRET)`.
 * Sans `APP_SECRET`, l'attaquant ne peut plus produire de couple valide : un
 * cookie forgé est rejeté à la vérification de signature, avant même la
 * comparaison cookie/header. Le secret ne quitte jamais le serveur.
 *
 * Choix délibéré : le jeton n'est pas lié à l'identité de l'utilisateur.
 * CsrfCookieRequestSubscriber s'exécute en priorité 20, *avant* le firewall
 * Security (priorité 8, contrainte imposée par le cas /api/logout) : aucun
 * utilisateur n'est encore authentifié à ce moment, une vérification
 * « le jeton appartient bien à l'appelant » n'a donc pas de point d'ancrage
 * fiable ici. La signature couvre à elle seule la menace (cookie forgé) que
 * la liaison à l'identité viserait par ailleurs.
 */
final readonly class CsrfCookieTokenSigner
{
    private const string SEPARATOR = '.';

    /** Entropie de la partie aléatoire, en octets (64 caractères hexadécimaux). */
    private const int RANDOM_BYTES = 32;

    public function __construct(
        #[\SensitiveParameter]
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    /**
     * Nouvelle valeur de cookie signée, à poser au login.
     */
    public function issue(): string
    {
        $random = bin2hex(random_bytes(self::RANDOM_BYTES));

        return $random.self::SEPARATOR.$this->signature($random);
    }

    /**
     * Vrai si `$token` est une valeur `<random>.<hmac>` dont la signature
     * correspond à ce serveur. Ne compare pas au cookie/header : c'est le
     * rôle (préalable) de CsrfCookieRequestSubscriber.
     */
    public function isValid(string $token): bool
    {
        $parts = explode(self::SEPARATOR, $token);

        if (2 !== \count($parts)) {
            return false;
        }

        [$random, $providedSignature] = $parts;

        if ('' === $random || '' === $providedSignature) {
            return false;
        }

        return hash_equals($this->signature($random), $providedSignature);
    }

    private function signature(string $random): string
    {
        return hash_hmac('sha256', $random, $this->secret);
    }
}
