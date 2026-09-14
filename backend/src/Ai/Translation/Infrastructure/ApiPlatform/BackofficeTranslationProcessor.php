<?php

declare(strict_types=1);

namespace App\Ai\Translation\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ai\Translation\Application\ContentTranslatorInterface;
use App\Ai\Translation\Domain\ValueObject\TranslationRequest;
use App\Ai\Translation\Presentation\ApiResource\BackofficeTranslationResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;

/**
 * Ordre volontaire : la validation du DTO a déjà eu lieu (422) quand on
 * arrive ici, l'appel au fournisseur vient en dernier. Les locales viennent
 * d'un DTO borné par Assert\Choice, d'où Locale::from() et non fromString()
 * (règle audit I3 : une ValueError ici serait un vrai bug, pas un 404).
 *
 * @implements ProcessorInterface<BackofficeTranslationResource, BackofficeTranslationResource>
 */
final readonly class BackofficeTranslationProcessor implements ProcessorInterface
{
    public function __construct(
        private ContentTranslatorInterface $contentTranslator,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BackofficeTranslationResource
    {
        \assert(null !== $data->sourceLocale && null !== $data->targetLocale);

        $translated = $this->contentTranslator->translate(new TranslationRequest(
            Locale::from($data->sourceLocale),
            Locale::from($data->targetLocale),
            $data->validatedFields(),
        ));

        return new BackofficeTranslationResource($data->sourceLocale, $data->targetLocale, $translated->fields);
    }
}
