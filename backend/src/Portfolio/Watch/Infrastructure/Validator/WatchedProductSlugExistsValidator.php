<?php

declare(strict_types=1);

namespace App\Portfolio\Watch\Infrastructure\Validator;

use App\Portfolio\Watch\Domain\Exception\ReleaseCycleSourceUnavailableException;
use App\Portfolio\Watch\Domain\Service\ReleaseCycleSourceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class WatchedProductSlugExistsValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ReleaseCycleSourceInterface $releaseCycleSource,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof WatchedProductSlugExists) {
            throw new UnexpectedTypeException($constraint, WatchedProductSlugExists::class);
        }

        // Un slug absent ou mal formé relève de NotBlank et de Regex : les
        // signaler une seconde fois n'aiderait personne, et surtout n'appelons
        // pas un tiers pour une valeur qu'on sait déjà invalide.
        if (null === $value || '' === $value) {
            return;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }

        try {
            $exists = $this->releaseCycleSource->supportsProduct($value);
        } catch (ReleaseCycleSourceUnavailableException $exception) {
            /*
             * Cœur de la décision D10 : la vérification n'a pas abouti, donc on
             * ne sait rien — et on ne bloque pas. Une panne chez le fournisseur
             * ne doit jamais empêcher d'administrer son propre site, d'autant
             * que la conséquence d'un slug fautif se limite à une ligne
             * affichée « inconnue », réparable à tout moment.
             */
            $this->logger->warning('Vérification du slug impossible, saisie acceptée sans contrôle.', [
                'slug' => $value,
                'exception' => $exception,
            ]);

            return;
        }

        if (!$exists) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ slug }}', $value)
                ->addViolation();
        }
    }
}
