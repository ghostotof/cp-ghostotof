<?php

declare(strict_types=1);

namespace App\Ai\Translation\Domain\ValueObject;

use App\Ai\Translation\Domain\Exception\InvalidTranslationRequestException;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Ce qu'on demande au traducteur : un dictionnaire `nom de champ => texte`, à
 * passer d'une locale à l'autre. Le contexte ignore volontairement ce que sont
 * ces champs (un incident, une étude de cas…) : c'est le frontend qui choisit
 * la prose et recopie le reste (spec 0002, D2).
 */
final readonly class TranslationRequest
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public Locale $sourceLocale,
        public Locale $targetLocale,
        public array $fields,
    ) {
        if ($sourceLocale === $targetLocale) {
            throw InvalidTranslationRequestException::identicalLocales($sourceLocale->value);
        }

        if ([] === $fields) {
            throw InvalidTranslationRequestException::emptyDictionary();
        }

        foreach ($fields as $name => $value) {
            if ('' === trim($value)) {
                throw InvalidTranslationRequestException::blankValue($name);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function fieldNames(): array
    {
        return array_keys($this->fields);
    }
}
