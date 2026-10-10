<?php

declare(strict_types=1);

namespace App\Shared\Presentation\ApiResource;

/**
 * Un DTO d'écriture contient une valeur que sa validation aurait dû refuser
 * (issue #338) : l'entrée contredit la validation, qui a pourtant tourné.
 *
 * Levée par les accesseurs qui rendent l'entrée sous la forme garantie par les
 * contraintes (`keys()`, `validatedCategory()`, `validatedFields()`) : quand on
 * les appelle, la validation a déjà tranché en 422. Une valeur qui la contredit
 * est un défaut du pipeline, jamais une saisie — 500 `critical`, sans
 * `ProblemExceptionInterface`, plutôt qu'un filtrage silencieux qui traiterait
 * une entrée amputée. Une fabrique par cas, et jamais la valeur elle-même dans
 * le message.
 */
final class InputContradictsValidationException extends \LogicException
{
    /**
     * Longueur maximale d'un nom de champ cité : celle qu'admet
     * `BackofficeTranslationResource::FIELD_NAME_PATTERN`.
     */
    private const int QUOTED_NAME_LENGTH = 40;

    public static function nonTextualOrderKey(): self
    {
        return self::because('clé d\'ordre non textuelle');
    }

    public static function missingCategory(): self
    {
        return self::because('catégorie absente');
    }

    /**
     * Le nom vient du client, et la validation qui devait le borner n'a pas
     * tranché : il est réduit aux lettres et chiffres, et tronqué, avant
     * d'atteindre le journal (revue de sécurité de #338).
     */
    public static function nonTextualField(int|string $name): self
    {
        $harmless = (string) preg_replace('/[^A-Za-z0-9]/', '?', (string) $name);
        if (mb_strlen($harmless) > self::QUOTED_NAME_LENGTH) {
            $harmless = mb_substr($harmless, 0, self::QUOTED_NAME_LENGTH).'…';
        }

        return self::because(\sprintf('champ "%s" non textuel', $harmless));
    }

    private static function because(string $what): self
    {
        return new self(\sprintf('Entrée qui contredit la validation : %s.', $what));
    }
}
