<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/backoffice/users : invite un utilisateur à partir de son
 * adresse e-mail. `locale` (fr/en) pilote la langue de l'e-mail d'invitation
 * et du lien envoyé. Défaut vide volontaire : une locale absente échoue le
 * `NotBlank` (422) plutôt que de retomber silencieusement sur une valeur.
 */
final readonly class BackofficeUserInviteInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public string $email = '',
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['fr', 'en'])]
        public string $locale = '',
    ) {
    }
}
