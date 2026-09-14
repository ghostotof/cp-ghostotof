<?php

declare(strict_types=1);

namespace App\Ai\Translation\Presentation\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Ai\Translation\Infrastructure\ApiPlatform\BackofficeTranslationProcessor;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Assistant de traduction du backoffice (spec 0002, ADR 0004), réservé
 * ROLE_SUPER par la règle `^/api/backoffice(/|$)` de security.yaml — aucune
 * entrée d'allow-list dans ApiRouteExposureTest, et il ne doit jamais y en
 * avoir. Une seule opération, `status: 200` : rien n'est créé ni persisté, la
 * réponse est le même dictionnaire, traduit. Le brouillon obtenu n'entre en
 * base que si l'humain l'enregistre ensuite par le formulaire habituel (D4).
 *
 * Agnostique du contenu (D2) : le frontend choisit les champs de prose de
 * chaque page et recopie le reste. Les bornes ci-dessous sont la seule chose
 * que le serveur puisse valider sans connaître le sens des champs — elles
 * bornent aussi ce qui part vers le fournisseur.
 */
#[ApiResource(
    shortName: 'BackofficeTranslation',
    operations: [
        new Post(
            uriTemplate: '/backoffice/translations',
            status: 200,
            processor: BackofficeTranslationProcessor::class,
        ),
    ],
)]
final class BackofficeTranslationResource
{
    public const int MAX_FIELDS = 12;
    public const int MAX_FIELD_LENGTH = 20_000;
    public const int MAX_TOTAL_LENGTH = 40_000;
    public const string FIELD_NAME_PATTERN = '/^[a-zA-Z][a-zA-Z0-9]{0,39}$/';

    /**
     * Entrée non fiable tant que la validation n'a pas tranché : les clés
     * peuvent être numériques (PHP convertit "123" en int), les valeurs de
     * n'importe quel type. validatedFields() rend la forme garantie.
     *
     * @param array<int|string, mixed> $fields
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['fr', 'en'])]
        public ?string $sourceLocale = null,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['fr', 'en'])]
        public ?string $targetLocale = null,
        #[Assert\Count(min: 1, max: self::MAX_FIELDS)]
        #[Assert\All([
            new Assert\Type('string'),
            new Assert\NotBlank(normalizer: 'trim'),
            new Assert\Length(max: self::MAX_FIELD_LENGTH),
        ])]
        public array $fields = [],
    ) {
    }

    /**
     * Ce que les contraintes déclaratives ne couvrent pas : les locales doivent
     * différer, les noms de champs respecter le motif, et le total rester borné.
     */
    #[Assert\Callback]
    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if (null !== $this->sourceLocale && $this->sourceLocale === $this->targetLocale) {
            $context->buildViolation('La locale cible doit différer de la locale source.')
                ->atPath('targetLocale')
                ->addViolation();
        }

        $total = 0;
        foreach ($this->fields as $name => $value) {
            $name = (string) $name;
            if (1 !== preg_match(self::FIELD_NAME_PATTERN, $name)) {
                $context->buildViolation('Nom de champ invalide : "{{ name }}".')
                    ->setParameter('{{ name }}', $name)
                    ->atPath('fields')
                    ->addViolation();
            }

            if (\is_string($value)) {
                $total += mb_strlen($value);
            }
        }

        if ($total > self::MAX_TOTAL_LENGTH) {
            $context->buildViolation('Le contenu à traduire dépasse {{ limit }} caractères au total.')
                ->setParameter('{{ limit }}', (string) self::MAX_TOTAL_LENGTH)
                ->atPath('fields')
                ->addViolation();
        }
    }

    /**
     * Le dictionnaire tel que la validation le garantit. À n'appeler qu'après
     * elle : une valeur non textuelle ici est un défaut du pipeline, pas une
     * saisie — d'où l'exception logique plutôt qu'un filtrage silencieux.
     *
     * @return array<string, string>
     */
    public function validatedFields(): array
    {
        $fields = [];
        foreach ($this->fields as $name => $value) {
            if (!\is_string($value)) {
                throw new \LogicException(\sprintf('Le champ "%s" aurait dû être refusé par la validation.', (string) $name));
            }
            $fields[(string) $name] = $value;
        }

        return $fields;
    }
}
