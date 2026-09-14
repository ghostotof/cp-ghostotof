<?php

declare(strict_types=1);

namespace App\Ai\Translation\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Ai\Translation\Application\ContentTranslatorInterface;
use App\Ai\Translation\Application\TranslationRateLimiterInterface;
use App\Ai\Translation\Domain\ValueObject\TranslationRequest;
use App\Ai\Translation\Presentation\ApiResource\BackofficeTranslationResource;
use App\Portfolio\Shared\Domain\ValueObject\Locale;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Ordre volontaire : la validation du DTO a déjà eu lieu (422) quand on
 * arrive ici ; le quota du compte est consommé ensuite (429) — une requête
 * invalide ne coûte rien — et l'appel au fournisseur vient en dernier. Pas de
 * listener kernel.request pour ce quota, contrairement aux limiteurs des
 * routes anonymes (audit C1) : l'appelant est authentifié, il n'y a pas de
 * quota d'IP à protéger d'une charge non validée. Les locales viennent d'un
 * DTO borné par Assert\Choice, d'où Locale::from() et non fromString()
 * (règle audit I3 : une ValueError ici serait un vrai bug, pas un 404).
 *
 * @implements ProcessorInterface<BackofficeTranslationResource, BackofficeTranslationResource>
 */
final readonly class BackofficeTranslationProcessor implements ProcessorInterface
{
    public function __construct(
        private ContentTranslatorInterface $contentTranslator,
        private TranslationRateLimiterInterface $translationRateLimiter,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BackofficeTranslationResource
    {
        \assert(null !== $data->sourceLocale && null !== $data->targetLocale);

        // access_control (^/api/backoffice) garantit déjà une authentification :
        // garde explicite plutôt qu'un TypeError 500 opaque (même patron que
        // BackofficeUserRoleProcessor).
        $actingUser = $this->security->getUser();
        if (!$actingUser instanceof UserInterface) {
            throw new \LogicException('BackofficeTranslationProcessor::process() appelé sans utilisateur authentifié.');
        }

        $this->translationRateLimiter->consume($actingUser->getUserIdentifier());

        $translated = $this->contentTranslator->translate(new TranslationRequest(
            Locale::from($data->sourceLocale),
            Locale::from($data->targetLocale),
            $data->validatedFields(),
        ));

        return new BackofficeTranslationResource($data->sourceLocale, $data->targetLocale, $translated->fields);
    }
}
