<?php

declare(strict_types=1);

namespace App\Tests\Security\Authentication;

use App\Security\User\Domain\Entity\CpgUser;
use App\Tests\Support\EnvironmentConfigKernel;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\MessageDigestPasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\MigratingPasswordHasher;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\Hasher\Pbkdf2PasswordHasher;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Le hasher des comptes, vu du constat A10 (issue #590) : un échec de login
 * doit coûter une seule dérivation, quel que soit l'âge du hachage stocké.
 *
 * Avec `algorithm: auto`, Symfony construit `MigratingPasswordHasher(Native,
 * Sodium, Pbkdf2, MessageDigest)`. Quand la vérification échoue sur un hachage
 * que `needsRehash()` désigne (coût ou algorithme d'avant la configuration
 * courante), il essaie les hashers secondaires — et `SodiumPasswordHasher`
 * retombe sur `password_verify()` pour un hachage bcrypt : une seconde
 * dérivation complète. Le chronomètre désignait alors les comptes au hachage
 * ancien. `algorithm: bcrypt` ne garde en secondaires que Pbkdf2 et
 * MessageDigest, qui refusent un hachage contenant un `$` (ou d'une autre
 * longueur que la leur) avant de rien calculer.
 *
 * Ce qui reste, accepté et documenté (`FailedLoginTimingEqualizer`,
 * `.claude/rules/security-authentication-connexion.md`) : cette dérivation unique se
 * fait au coût **stocké**, pas au coût configuré — un compte au hachage
 * ancien reste distinguable jusqu'à sa prochaine connexion réussie, qui le
 * rehache (`StalePasswordHashRehashTest`). D'où le second test : algorithme
 * et coût sont écrits en clair dans chaque environnement, pour qu'un
 * changement soit un diff relu de `security.yaml` (procédure dans
 * `k8s/README.md`, « Avant de changer `password_hashers` ») et jamais un
 * nouveau défaut de Symfony reçu par `composer update`.
 */
final class CpgUserPasswordHasherTest extends KernelTestCase
{
    /**
     * L'entrée entière que chaque environnement déclare pour `CpgUser` :
     * `prod` (préproduction et production tournent en APP_ENV=prod) et `dev`
     * au coût réel, `test` au coût minimal de bcrypt, pour la vitesse de la
     * suite. Entière, pour qu'une clé ajoutée — `id` d'un hasher maison, qui
     * remplace tout le reste, `migrate_from`, `hash_algorithm` — fasse rougir.
     */
    private const array HASHER_DECLARED_PER_ENVIRONMENT = [
        'prod' => ['algorithm' => 'bcrypt', 'cost' => 13],
        'dev' => ['algorithm' => 'bcrypt', 'cost' => 13],
        'test' => ['algorithm' => 'bcrypt', 'cost' => 4],
    ];

    /**
     * Les seuls secondaires admis : leur `verify()` compare la longueur du
     * hachage et refuse un `$` avant toute dérivation (vendor
     * symfony/password-hasher). Un autre secondaire devra montrer qu'il en
     * fait autant avant d'entrer dans cette liste.
     */
    private const array SECONDARY_HASHERS_REFUSING_CRYPT_FORMATS = [
        Pbkdf2PasswordHasher::class,
        MessageDigestPasswordHasher::class,
    ];

    /**
     * Un hasher secondaire qui sait vérifier un hachage bcrypt ou argon a
     * forcément dérivé pour le faire : c'est la seconde dérivation de l'issue.
     * Deux preuves : avec le **bon** mot de passe, aucun secondaire n'accepte
     * un hachage ancien (comportement, ce qui rougissait sous `auto` :
     * Sodium l'acceptait) ; et chaque secondaire est d'une classe connue pour
     * refuser avant de calculer — un secondaire qui dériverait puis refuserait
     * passerait la première preuve, pas la seconde. Le hasher des comptes, lui,
     * doit toujours accepter le hachage ancien : un tel compte se connecte
     * encore.
     */
    public function testNoSecondaryHasherCanVerifyAStaleBcryptOrArgonHash(): void
    {
        $hasher = $this->cpgUserHasher();
        $password = bin2hex(random_bytes(8));
        $staleHashes = [
            // Un coût au-dessus de celui de test : `needsRehash()` est vrai.
            'bcrypt coût 5' => password_hash($password, \PASSWORD_BCRYPT, ['cost' => 5]),
        ];
        // L'image du backend a argon2 ; un PHP compilé sans ne perd que ce cas.
        if (\defined('PASSWORD_ARGON2ID')) {
            $staleHashes['argon2id'] = password_hash($password, \PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        }

        $secondaries = $this->secondaryHashers($hasher);
        self::assertSame(
            self::SECONDARY_HASHERS_REFUSING_CRYPT_FORMATS,
            array_map(static fn (PasswordHasherInterface $secondary): string => $secondary::class, $secondaries),
            'Les hashers secondaires de CpgUser ont changé : vérifier qu\'aucun ne dérive sur un hachage bcrypt ou argon (issue #590).',
        );

        foreach ($staleHashes as $label => $staleHash) {
            self::assertTrue($hasher->needsRehash($staleHash), $label.' : le hachage devrait être à rehacher, le test ne prouve rien sinon.');
            self::assertTrue($hasher->verify($staleHash, $password), $label.' : un compte au hachage ancien doit encore pouvoir se connecter.');

            foreach ($secondaries as $secondary) {
                self::assertFalse(
                    $secondary->verify($staleHash, $password),
                    \sprintf('%s : %s vérifie ce hachage, donc dérive une seconde fois après l\'échec du hasher principal (issue #590).', $label, $secondary::class),
                );
            }
        }
    }

    /**
     * La configuration déclarée, environnement par environnement : `CpgUser`
     * seul dans `password_hashers` (une entrée plus générale déclarée avant
     * lui, une interface qu'il implémente, gagnerait — la fabrique prend la
     * première qui correspond), et son entrée exacte. `CpgUser` ne choisit pas
     * non plus son hasher lui-même (`PasswordHasherAwareInterface`).
     */
    public function testEveryEnvironmentWritesTheAlgorithmAndTheCostExplicitly(): void
    {
        self::assertFalse(
            is_subclass_of(CpgUser::class, PasswordHasherAwareInterface::class),
            'CpgUser choisit son hasher lui-même : la configuration lue ici ne décide plus.',
        );

        $declared = [];
        foreach (EnvironmentConfigKernel::allowedEnvironments() as $environment) {
            $hashers = new EnvironmentConfigKernel($environment)->declaredMapping('security', 'password_hashers');
            self::assertSame([CpgUser::class], array_keys($hashers), $environment.' : password_hashers ne doit déclarer que CpgUser.');

            $entry = $hashers[CpgUser::class];
            if (\is_array($entry)) {
                // L'ordre des clés dans le YAML ne change rien au hasher.
                ksort($entry);
            }
            $declared[$environment] = $entry;
        }

        $expected = self::HASHER_DECLARED_PER_ENVIRONMENT;
        foreach ($expected as &$entry) {
            ksort($entry);
        }

        self::assertSame(
            $expected,
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
        // Pas de liste vide par défaut : un autre type de hasher (décorateur,
        // hasher maison) laisserait passer la boucle sans rien vérifier.
        self::assertInstanceOf(
            MigratingPasswordHasher::class,
            $hasher,
            'Le hasher de CpgUser n\'est plus celui que Symfony construit pour `bcrypt` : relire ce test et l\'issue #590.',
        );

        /** @var iterable<PasswordHasherInterface> $extra */
        $extra = new ReflectionProperty(MigratingPasswordHasher::class, 'extraHashers')->getValue($hasher);

        return array_values([...$extra]);
    }
}
