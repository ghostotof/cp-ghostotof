<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\EnvironmentConfigKernel;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\MigratingPasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Le hasher des comptes, vu du constat A10 (issue #590) : un échec de login
 * doit coûter une seule dérivation, quel que soit l'âge du hachage stocké.
 *
 * Avec `algorithm: auto`, Symfony construit `MigratingPasswordHasher(Native,
 * Sodium, Pbkdf2)`. Quand la vérification échoue sur un hachage que
 * `needsRehash()` désigne (coût ou algorithme d'avant la configuration
 * courante), il essaie les hashers secondaires — et `SodiumPasswordHasher`
 * retombe sur `password_verify()` pour un hachage bcrypt : une seconde
 * dérivation complète. Le chronomètre désignait alors les comptes au hachage
 * ancien. `algorithm: bcrypt` ne garde en secondaires que Pbkdf2 et
 * MessageDigest, qui refusent sur-le-champ tout hachage contenant un `$`.
 *
 * Ce qui reste, accepté et documenté (`FailedLoginTimingEqualizer`,
 * `.claude/rules/security-authentication.md`) : cette dérivation unique se
 * fait au coût **stocké**, pas au coût configuré — un compte au hachage
 * ancien reste distinguable jusqu'à sa prochaine connexion réussie, qui le
 * rehache. D'où le second test : algorithme et coût sont écrits en clair
 * dans chaque environnement, pour qu'un changement soit un diff relu de
 * `security.yaml` (procédure dans `k8s/README.md`, « Avant de changer
 * `password_hashers` ») et jamais un nouveau défaut de Symfony reçu par
 * `composer update`.
 */
final class CpgUserPasswordHasherTest extends KernelTestCase
{
    /**
     * Ce que chaque environnement déclare pour `CpgUser` : `prod` (préproduction
     * et production tournent en APP_ENV=prod) et `dev` au coût réel, `test` au
     * coût minimal de bcrypt, pour la vitesse de la suite.
     */
    private const array EXPECTED = [
        'prod' => ['algorithm' => 'bcrypt', 'cost' => 13],
        'dev' => ['algorithm' => 'bcrypt', 'cost' => 13],
        'test' => ['algorithm' => 'bcrypt', 'cost' => 4],
    ];

    /**
     * Un hasher secondaire qui sait vérifier un hachage bcrypt ou argon a
     * forcément dérivé pour le faire : c'est la seconde dérivation de l'issue.
     * Le critère est donc comportemental — avec le **bon** mot de passe, aucun
     * secondaire ne doit accepter un hachage ancien —, et le hasher des comptes,
     * lui, doit toujours l'accepter : un compte au hachage ancien se connecte
     * encore, et sa connexion réussie le rehache.
     */
    public function testNoSecondaryHasherCanVerifyAStaleBcryptOrArgonHash(): void
    {
        $hasher = $this->cpgUserHasher();
        $password = bin2hex(random_bytes(8));
        $staleHashes = [
            // Un coût au-dessus de celui de test : `needsRehash()` est vrai.
            'bcrypt coût 5' => password_hash($password, \PASSWORD_BCRYPT, ['cost' => 5]),
            'argon2id' => password_hash($password, \PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]),
        ];

        foreach ($staleHashes as $label => $staleHash) {
            self::assertTrue($hasher->needsRehash($staleHash), $label.' : le hachage devrait être à rehacher, le test ne prouve rien sinon.');
            self::assertTrue($hasher->verify($staleHash, $password), $label.' : un compte au hachage ancien doit encore pouvoir se connecter.');

            foreach ($this->secondaryHashers($hasher) as $secondary) {
                self::assertFalse(
                    $secondary->verify($staleHash, $password),
                    \sprintf('%s : %s vérifie ce hachage, donc dérive une seconde fois après l\'échec du hasher principal (issue #590).', $label, $secondary::class),
                );
            }
        }
    }

    public function testEveryEnvironmentWritesTheAlgorithmAndTheCostExplicitly(): void
    {
        $declared = [];
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            $hashers = new EnvironmentConfigKernel($environment)->declaredMapping('security', 'password_hashers');
            $config = $hashers[CpgUser::class] ?? null;
            $declared[$environment] = \is_array($config)
                ? array_intersect_key($config, ['algorithm' => true, 'cost' => true, 'migrate_from' => true])
                : $config;
        }

        self::assertSame(
            self::EXPECTED,
            $declared,
            'Le hasher de CpgUser a changé : lire « Avant de changer password_hashers » dans k8s/README.md (diagnostic SQL, comptes au hachage ancien distinguables au chronomètre), puis mettre ce test à jour.',
        );
    }

    private function cpgUserHasher(): PasswordHasherInterface
    {
        return self::getContainer()->get(PasswordHasherFactoryInterface::class)->getPasswordHasher(CpgUser::class);
    }

    /**
     * Les hashers que `MigratingPasswordHasher::verify()` essaie après l'échec
     * du principal. Propriété privée du vendor : réflexion, ici seulement.
     *
     * @return list<PasswordHasherInterface>
     */
    private function secondaryHashers(PasswordHasherInterface $hasher): array
    {
        if (!$hasher instanceof MigratingPasswordHasher) {
            return [];
        }

        /** @var iterable<PasswordHasherInterface> $extra */
        $extra = new ReflectionProperty(MigratingPasswordHasher::class, 'extraHashers')->getValue($hasher);

        return array_values([...$extra]);
    }
}
