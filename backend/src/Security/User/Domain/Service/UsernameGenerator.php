<?php

declare(strict_types=1);

namespace App\Security\User\Domain\Service;

use App\Security\User\Domain\Repository\CpgUserRepositoryInterface;

/**
 * Dérive un nom d'utilisateur libre à partir d'une adresse e-mail, pour les
 * comptes créés par invitation depuis le backoffice (cf. CpgUserInviter).
 *
 * Règles :
 * - on part de la partie locale de l'e-mail (avant le "@"), passée en minuscules ;
 * - on ne conserve que les caractères autorisés par CpgUser::USERNAME_PATTERN
 *   ([a-z0-9_.-]) ;
 * - le résultat est borné à 60 caractères ; s'il fait moins de 3 caractères,
 *   il est complété par "user" ;
 * - en cas de collision avec un compte existant, un suffixe numérique croissant
 *   (2, 3, 4, ...) est ajouté, la base étant tronquée si besoin pour ne jamais
 *   dépasser 60 caractères.
 */
final readonly class UsernameGenerator
{
    /** Doit rester cohérent avec CpgUser::USERNAME_PATTERN ([a-zA-Z0-9_.-]{3,60}). */
    private const int MIN_LENGTH = 3;
    private const int MAX_LENGTH = 60;

    public function __construct(
        private CpgUserRepositoryInterface $cpgUserRepository,
    ) {
    }

    public function generateFromEmail(string $email): string
    {
        $base = $this->baseFromEmail($email);

        if (null === $this->cpgUserRepository->findOneByUsername($base)) {
            return $base;
        }

        $suffix = 2;

        while (true) {
            $candidate = $this->withSuffix($base, (string) $suffix);

            if (null === $this->cpgUserRepository->findOneByUsername($candidate)) {
                return $candidate;
            }

            ++$suffix;
        }
    }

    private function baseFromEmail(string $email): string
    {
        $atPosition = strpos($email, '@');
        $localPart = false === $atPosition ? $email : substr($email, 0, $atPosition);

        $base = preg_replace('/[^a-z0-9_.-]/', '', strtolower($localPart)) ?? '';
        $base = substr($base, 0, self::MAX_LENGTH);

        if (\strlen($base) < self::MIN_LENGTH) {
            return substr($base.'user', 0, self::MAX_LENGTH);
        }

        return $base;
    }

    private function withSuffix(string $base, string $suffix): string
    {
        $maxBaseLength = self::MAX_LENGTH - \strlen($suffix);

        return substr($base, 0, $maxBaseLength).$suffix;
    }
}
