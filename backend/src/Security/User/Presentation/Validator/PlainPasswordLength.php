<?php

declare(strict_types=1);

namespace App\Security\User\Presentation\Validator;

use App\Security\User\Domain\Entity\CpgUser;
use Attribute;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * La longueur d'un mot de passe en clair, écrite une fois pour les trois
 * voies qui en reçoivent un : `app:user:create`, le changement de mot de
 * passe du backoffice et la définition par lien d'invitation (issue #386).
 *
 * Les deux bornes ne se comptent pas dans la même unité, et c'est voulu :
 *  - le minimum en **caractères**, la règle que lit la personne qui choisit
 *    son mot de passe ;
 *  - le maximum en **octets**, la borne du hasher
 *    ({@see \Symfony\Component\PasswordHasher\PasswordHasherInterface::MAX_PASSWORD_LENGTH},
 *    contrôlée par `strlen`). Comptée en caractères, elle laissait passer un
 *    mot de passe multioctet que le hasher refusait ensuite : un 500.
 *
 * La CLI mesurait le minimum en octets, l'API le maximum en caractères : une
 * seule définition supprime les deux écarts. Une saisie qui n'est pas de
 * l'UTF-8 valide est refusée par la même contrainte (Length vérifie le jeu de
 * caractères) ; seule l'entrée standard de la CLI peut en porter.
 *
 * Pattern Composite (Compound de Symfony) plutôt que deux attributs recopiés
 * à chaque site : la règle a déjà divergé une fois entre ses copies.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class PlainPasswordLength extends Compound
{
    /**
     * @param array<string, mixed> $options
     *
     * @return list<Assert\Length>
     */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Length(min: CpgUser::MIN_PASSWORD_LENGTH),
            new Assert\Length(max: CpgUser::MAX_PASSWORD_LENGTH, countUnit: Assert\Length::COUNT_BYTES),
        ];
    }
}
