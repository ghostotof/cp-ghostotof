<?php

declare(strict_types=1);

namespace App\Security\Authentication\Infrastructure\Log;

use App\Security\User\Domain\ValueObject\GuestUser;
use App\Shared\Domain\Security\AccountReference;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

/**
 * Retire l'identifiant de connexion des lignes que Symfony Security écrit sur
 * son canal `security` (issue #363), journalisé en `info` en production comme
 * `security_audit` (monolog.yaml).
 *
 * Deux lignes du framework le citaient :
 *
 *  - « Authenticator successful! », à **chaque requête authentifiée** du
 *    firewall `api` (le JWT est ré-authentifié à chaque appel) : le contexte
 *    `token` est l'objet jeton, que le formateur JSON rend par son
 *    `__toString()` — `JWTPostAuthenticationToken(user="jean.dupont",
 *    roles="ROLE_TRUSTED, ROLE_USER")`. L'identifiant d'un invité est dérivé
 *    de son adresse (ADR 0001 D2) : c'était l'historique nominatif de ses
 *    requêtes, que l'access log de nginx complète de l'IP et de l'URL ;
 *  - « Authenticator failed. », dont l'exception porte en `previous` (ou nue)
 *    le UserNotFoundException du fournisseur, `There is no user with
 *    identifier "…"` : un JWT encore valide d'un compte supprimé.
 *
 * Le jeton est donc réécrit en `Classe(userId="…", roles="…")` — le marqueur
 * `guest-…` du palier de base, aléatoire, reste écrit (`user=`), tout autre
 * utilisateur n'est désigné que par ses rôles —, et toute exception dont la
 * chaîne contient un UserNotFoundException est remplacée par sa forme
 * normalisée (classe, message, code, fichier:ligne, `previous`, celle du
 * formateur JSON sans la pile), le message de ce maillon remplacé par
 * {@see self::WITHHELD}. Les autres maillons gardent leur message : aucun ne
 * cite l'identifiant (`Bad credentials.`, ceux de Lexik). Les deux lignes
 * restent écrites — « Authenticator failed. » est la trace d'un JWT forgé ou
 * expiré.
 *
 * Un processeur plutôt que relever le handler `security` à `notice` (qui
 * perdait « Authenticator failed. ») ou réécrire le formateur (qui aurait
 * touché tous les canaux). Limité à `security` : c'est le seul canal où ces
 * objets arrivent ; AccountIdentifierReachesNoLogTest, qui lit tous les
 * canaux, verrait un nouveau site ailleurs.
 */
#[AsMonologProcessor(channel: 'security')]
final class SecurityChannelAccountRedactor
{
    public const string WITHHELD = 'User not found (identifier withheld, see issue #363).';

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $changed = false;

        foreach ($context as $key => $value) {
            $redacted = match (true) {
                $value instanceof TokenInterface => $this->describeToken($value),
                $value instanceof UserInterface => $this->describeUser($value),
                $value instanceof Throwable && $this->quotesAnIdentifier($value) => self::normalizedWithoutIdentifier($value),
                default => $value,
            };

            if ($redacted !== $value) {
                $context[$key] = $redacted;
                $changed = true;
            }
        }

        return $changed ? $record->with(context: $context) : $record;
    }

    private function describeToken(TokenInterface $token): string
    {
        $user = $token->getUser();
        $subject = null !== $user ? $this->subjectOf($user) : '';

        return \sprintf('%s(%sroles="%s")', $this->shortName($token), $subject, implode(', ', $token->getRoleNames()));
    }

    private function describeUser(UserInterface $user): string
    {
        return \sprintf('%s(%sroles="%s")', $this->shortName($user), $this->subjectOf($user), implode(', ', $user->getRoles()));
    }

    /** `userId="…", ` pour un compte, `user="guest-…", ` pour le palier de base, rien sinon. */
    private function subjectOf(UserInterface $user): string
    {
        return match (true) {
            $user instanceof AccountReference => \sprintf('userId="%s", ', $user->accountId()),
            $user instanceof GuestUser => \sprintf('user="%s", ', $user->getUserIdentifier()),
            default => '',
        };
    }

    private function quotesAnIdentifier(Throwable $exception): bool
    {
        for ($link = $exception; null !== $link; $link = $link->getPrevious()) {
            if ($link instanceof UserNotFoundException) {
                return true;
            }
        }

        return false;
    }

    /**
     * La forme que JsonFormatter donne à une exception (sans la pile, comme en
     * production), le message d'un UserNotFoundException masqué.
     *
     * @return array<string, mixed>
     */
    private static function normalizedWithoutIdentifier(Throwable $exception): array
    {
        $normalized = [
            'class' => $exception::class,
            'message' => $exception instanceof UserNotFoundException ? self::WITHHELD : $exception->getMessage(),
            'code' => (int) $exception->getCode(),
            'file' => $exception->getFile().':'.$exception->getLine(),
        ];

        $previous = $exception->getPrevious();
        if (null !== $previous) {
            $normalized['previous'] = self::normalizedWithoutIdentifier($previous);
        }

        return $normalized;
    }

    private function shortName(object $object): string
    {
        $class = $object::class;
        $separator = strrpos($class, '\\');

        return false === $separator ? $class : substr($class, $separator + 1);
    }
}
