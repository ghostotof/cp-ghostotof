<?php

declare(strict_types=1);

namespace App\Tests\Security\User\Fixtures;

use App\Security\User\Presentation\Validator\PlainPasswordLength;
use SensitiveParameter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fixture de {@see \App\Tests\Security\User\PlainPasswordInputsTest} (issue #411) : un DTO comme une
 * quatrième voie pourrait en écrire, avec ce que la garde doit voir et ce
 * qu'elle doit ignorer. Hors de src/, il ne publie aucune route.
 */
final class PlainPasswordFieldsFixture
{
    #[Assert\Sequentially([
        new Assert\NotBlank(),
        new PlainPasswordLength(),
        new Assert\NotCompromisedPassword(skipOnError: true),
    ])]
    public string $passwordWithTheSharedSequence = '';

    // La mutation que décrit l'issue : la borne recopiée à la main, en
    // caractères, d'où le 500 du hasher sur un mot de passe multioctet.
    #[Assert\NotBlank]
    #[Assert\Length(max: 4096)]
    public string $passwordWithAHandWrittenLength = '';

    // Le bon trio, dans le mauvais ordre : haveibeenpwned interrogé avant
    // que la longueur ait refusé quoi que ce soit.
    #[Assert\Sequentially([
        new Assert\NotBlank(),
        new Assert\NotCompromisedPassword(skipOnError: true),
        new PlainPasswordLength(),
    ])]
    public string $passwordInAnotherOrder = '';

    // Une contrainte hors de la séquence s'évalue même après un refus.
    #[Assert\Type('string')]
    #[Assert\Sequentially([
        new Assert\NotBlank(),
        new PlainPasswordLength(),
        new Assert\NotCompromisedPassword(skipOnError: true),
    ])]
    public string $passwordWithAnExtraConstraint = '';

    public string $newPassphrase = '';

    public mixed $motDePasse = null;

    public ?string $pwd = null;

    // Ignorés : un hash n'est pas une saisie, un service n'est pas une chaîne,
    // et un titre n'est pas un mot de passe.
    public string $hashedPassword = '';

    public ?UserPasswordHasherInterface $passwordHasher = null;

    public string $title = '';

    /** Deux mots de passe en clair, l'un caché des traces d'exception, l'autre non ; un hash, ignoré. */
    public function change(string $newPlainPassword, #[SensitiveParameter] string $plainPassword, string $hashedPassword): bool
    {
        return $newPlainPassword !== $plainPassword && '' !== $hashedPassword;
    }
}
